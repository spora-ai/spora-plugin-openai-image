<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the OpenAI-compatible
 * image endpoints.
 *
 * Single-shot: every failure surfaces to the caller so the LLM can adapt on
 * retry (lower quality, smaller size, raise `http_timeout_seconds`). An
 * internal retry loop would re-hit the same per-request cap and waste quota.
 */
final class OpenAIImageHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * POST /v1/images/generations — text-to-image.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $body): array
    {
        $response = $this->postJson('/images/generations', $body);
        return $this->decodeResponse($response);
    }

    /**
     * POST /v1/images/generations (prompt-based) or /v1/images/variations
     * (image-based, multipart upload). `input_image` is the switch: absent →
     * JSON generation, present → multipart upload to the variations endpoint.
     *
     * @param array<string, mixed> $body Must include `n` (defaults are applied upstream).
     * @return array<string, mixed>
     */
    public function generateVariations(array $body): array
    {
        $inputImage = $body['input_image'] ?? null;
        unset($body['input_image']);

        if (is_string($inputImage) && $inputImage !== '') {
            $response = $this->postMultipart('/images/variations', $body, $inputImage);
            return $this->decodeResponse($response);
        }

        return $this->generate($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $path, array $body): ResponseData
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new OpenAIImageException(
                'Image API request failed: ' . $e->getMessage()
                . " — try a smaller size or lower quality, or ask the operator to raise http_timeout_seconds (current: {$this->timeoutSeconds}s).",
                $this->timeoutSeconds,
                $e,
            );
        }
        $raw = $response->getContent(false);
        return new ResponseData($response->getStatusCode(), $raw, json_decode($raw, true));
    }

    /**
     * Multipart upload to /v1/images/variations. The upstream API only accepts
     * `image` (file), `n`, and `size` here — `prompt`, `quality`, and `background`
     * are not supported on the variations endpoint and are stripped if present.
     *
     * Symfony's HttpClient has no `multipart` option — it rejects the request
     * with `Unsupported option "multipart"`. It flips an array `body` to
     * `multipart/form-data` as soon as one value is a stream resource
     * ({@see \Symfony\Component\HttpClient\HttpClientTrait::normalizeBody()}),
     * so the image goes up as a resource and the scalar fields as strings.
     *
     * @param array<string, mixed> $body
     */
    private function postMultipart(string $path, array $body, string $inputImage): ResponseData
    {
        $image = $this->stageImage($this->fetchImage($inputImage));

        $fields = ['image' => $image['stream']];
        foreach (['n', 'size'] as $field) {
            if (isset($body[$field]) && $body[$field] !== '' && $body[$field] !== 'auto') {
                $fields[$field] = (string) $body[$field];
            }
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'body' => $fields,
                'timeout' => $this->timeoutSeconds,
            ]);
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new OpenAIImageException(
                'Image API request failed: ' . $e->getMessage()
                . " — try a smaller size, lower n, or ask the operator to raise http_timeout_seconds (current: {$this->timeoutSeconds}s).",
                $this->timeoutSeconds,
                $e,
            );
        } finally {
            $this->discardStagedImage($image);
        }

        return new ResponseData($response->getStatusCode(), $raw, json_decode($raw, true));
    }

    /**
     * Stage the image bytes in a temp file and open them as a stream.
     *
     * Symfony takes the multipart filename from the basename of the stream's
     * path, so the bytes go into a temp directory under the name `input.png`
     * — the name and the `.png` extension the upstream variations endpoint
     * expects for the image part.
     *
     * @return array{stream: resource, path: string, dir: string}
     */
    private function stageImage(string $bytes): array
    {
        $dir = sys_get_temp_dir() . '/spora_openai_image_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new OpenAIImageException('Failed to stage the input image for upload.', $this->timeoutSeconds);
        }

        $path = $dir . '/input.png';
        if (file_put_contents($path, $bytes) === false) {
            @rmdir($dir);
            throw new OpenAIImageException('Failed to stage the input image for upload.', $this->timeoutSeconds);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            @unlink($path);
            @rmdir($dir);
            throw new OpenAIImageException('Failed to stage the input image for upload.', $this->timeoutSeconds);
        }

        return ['stream' => $stream, 'path' => $path, 'dir' => $dir];
    }

    /**
     * @param array{stream: resource, path: string, dir: string} $image
     */
    private function discardStagedImage(array $image): void
    {
        if (is_resource($image['stream'])) {
            fclose($image['stream']);
        }
        @unlink($image['path']);
        @rmdir($image['dir']);
    }

    /**
     * Resolve an `input_image` reference to raw bytes: an http(s) URL is
     * fetched via the injected HttpClient, a `data:` URI is base64-decoded.
     *
     * Media Archive references are resolved to one of those two shapes
     * upstream, by {@see OpenAIImageMediaArchiveResolver}.
     */
    private function fetchImage(string $inputImage): string
    {
        if (str_starts_with($inputImage, 'data:')) {
            return $this->decodeDataUri($inputImage);
        }

        if (preg_match('#^https?://#i', $inputImage) === 1) {
            return $this->fetchHttpUrl($inputImage);
        }

        throw new OpenAIImageException(
            'input_image must be an http(s) URL or a data: URI; got an unrecognised value.',
            $this->timeoutSeconds,
        );
    }

    private function decodeDataUri(string $inputImage): string
    {
        $comma = strpos($inputImage, ',');
        if ($comma === false) {
            throw new OpenAIImageException('input_image data URI is malformed.', $this->timeoutSeconds);
        }
        $bytes = base64_decode(substr($inputImage, $comma + 1), true);
        if ($bytes === false) {
            throw new OpenAIImageException('input_image data URI is not valid base64.', $this->timeoutSeconds);
        }
        return $bytes;
    }

    private function fetchHttpUrl(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new OpenAIImageException('Failed to fetch input_image: ' . $e->getMessage(), $this->timeoutSeconds, $e);
        }
        $status = $response->getStatusCode();
        $bytes = $response->getContent(false);
        if ($status >= 400) {
            throw new OpenAIImageException("Failed to fetch input_image: HTTP {$status}.", $this->timeoutSeconds);
        }
        return $bytes;
    }

    private function decodeResponse(ResponseData $response): array
    {
        if ($response->status >= 400) {
            throw new OpenAIImageException($this->errorMessage($response->decoded, $response->status), $this->timeoutSeconds);
        }

        if (!is_array($response->decoded)) {
            throw new OpenAIImageException('Image API returned a non-JSON response.', $this->timeoutSeconds);
        }

        return $response->decoded;
    }

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Image API returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }
        return 'Image API returned HTTP ' . $status . '.';
    }
}

/**
 * The response triplet (status, raw body, decoded JSON). Carries the values
 * the decode path needs without handing it Symfony's `ResponseInterface`.
 *
 * @internal
 */
final class ResponseData
{
    /**
     * @param array<string, mixed>|null $decoded
     */
    public function __construct(
        public readonly int $status,
        public readonly string $raw,
        public readonly mixed $decoded,
    ) {}
}
