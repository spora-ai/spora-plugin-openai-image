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
    /**
     * The one message for a failed staging, so the throw sites and the
     * operator-facing text stay in step.
     */
    private const STAGE_FAILED = 'Failed to stage the input image for upload.';

    /**
     * The endpoint that takes a source image. It replaced the retired
     * `/images/variations`.
     */
    private const EDITS_PATH = '/images/edits';

    /**
     * The largest input image the plugin stages on disk.
     *
     * The bytes reach the temp file before the upstream ever sees them, so an
     * unbounded download fills the disk first — hence a cap below the
     * upstream's 50 MB limit for `/images/edits`. It is the number
     * {@see OpenAIImageMediaArchiveResolver} caps a Media Archive asset at,
     * which leaves that path untouched — the `data:` URI it builds stays well
     * below this in raw bytes.
     */
    private const MAX_STAGED_BYTES = 25 * 1024 * 1024;

    /**
     * The retired endpoint. OpenAI answers 404 for it, and so does every
     * provider that moved on to `/images/edits`.
     */
    private const VARIATIONS_PATH = '/images/variations';

    /**
     * `/images/edits` requires a prompt; the retired endpoint took none, so a
     * caller that named only a source image needs one spelled out here.
     */
    public const DEFAULT_VARIATION_PROMPT = 'Create a variation of the provided image.';

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
     * POST /v1/images/generations (prompt-based) or /v1/images/edits
     * (image-based, multipart upload). `input_image` is the switch: absent →
     * JSON generation, present → multipart upload to the edits endpoint.
     *
     * @param array<string, mixed> $body Must include `n` (defaults are applied upstream).
     * @return array<string, mixed>
     */
    public function generateVariations(array $body): array
    {
        $inputImage = $body['input_image'] ?? null;
        unset($body['input_image']);

        if (is_string($inputImage) && $inputImage !== '') {
            $response = $this->postMultipart($body, $inputImage);
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
        return new ResponseData($response->getStatusCode(), $raw, json_decode($raw, true), $path);
    }

    /**
     * Multipart upload of the source image to /v1/images/edits.
     *
     * The edits endpoint replaced the retired /v1/images/variations and takes
     * the image, the model and a prompt describing the wanted change; `n`,
     * `size`, `quality` and `background` are optional. A provider that never
     * implemented it answers 404, and the retired endpoint is tried then — it
     * accepted the image file, `n` and `size`, and nothing else.
     *
     * Symfony's HttpClient has no `multipart` option — it rejects the request
     * with `Unsupported option "multipart"`. It flips an array `body` to
     * `multipart/form-data` as soon as one value is a stream resource
     * ({@see \Symfony\Component\HttpClient\HttpClientTrait::normalizeBody()}),
     * so the image goes up as a resource and the scalar fields as strings.
     *
     * @param array<string, mixed> $body
     */
    private function postMultipart(array $body, string $inputImage): ResponseData
    {
        $image = $this->stageImage($this->fetchImage($inputImage));

        try {
            $response = $this->requestMultipart(self::EDITS_PATH, $this->editFields($body, $image));

            if ($response->status !== 404) {
                return $response;
            }

            // No edits endpoint here. The retired one is the only thing left
            // to try, and the image stream has to be rewound first — the
            // request above read it to the end.
            $this->rewind($image);
            $legacy = $this->requestMultipart(self::VARIATIONS_PATH, $this->variationFields($body, $image));

            // Both gone: the edits endpoint is the one to report, because it
            // is the one every current provider serves.
            return $legacy->status === 404 ? $response : $legacy;
        } finally {
            $this->discardStagedImage($image);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function requestMultipart(string $path, array $fields): ResponseData
    {
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
        }

        return new ResponseData($response->getStatusCode(), $raw, json_decode($raw, true), $path);
    }

    /**
     * The /v1/images/edits body: the source image, the model, and the prompt
     * describing the wanted change. `n`, `size`, `quality` and `background`
     * are optional and left out when they are still at their default.
     *
     * @param array<string, mixed> $body
     * @param array{stream: resource, path: string} $image
     * @return array<string, mixed>
     */
    private function editFields(array $body, array $image): array
    {
        $fields = ['image' => $image['stream']];

        if (isset($body['model']) && $body['model'] !== '') {
            $fields['model'] = (string) $body['model'];
        }

        $prompt = trim((string) ($body['prompt'] ?? ''));
        $fields['prompt'] = $prompt !== '' ? $prompt : self::DEFAULT_VARIATION_PROMPT;

        foreach (['n', 'size', 'quality', 'background'] as $field) {
            if (isset($body[$field]) && $body[$field] !== '' && $body[$field] !== 'auto') {
                $fields[$field] = (string) $body[$field];
            }
        }

        return $fields;
    }

    /**
     * The retired /v1/images/variations body: the image file, `n` and `size`.
     * The endpoint accepted nothing else, so the model, the prompt and the
     * quality settings are dropped.
     *
     * @param array<string, mixed> $body
     * @param array{stream: resource, path: string} $image
     * @return array<string, mixed>
     */
    private function variationFields(array $body, array $image): array
    {
        $fields = ['image' => $image['stream']];

        foreach (['n', 'size'] as $field) {
            if (isset($body[$field]) && $body[$field] !== '' && $body[$field] !== 'auto') {
                $fields[$field] = (string) $body[$field];
            }
        }

        return $fields;
    }

    /**
     * Stage the image bytes in a temp file and open them as a stream.
     *
     * The staged file carries the format of the bytes twice over: Symfony
     * takes the multipart filename from the basename of the stream's path and
     * the part's content type from the stream's context.
     *
     * @return array{stream: resource, path: string}
     */
    private function stageImage(string $bytes): array
    {
        $size = strlen($bytes);

        // Refused before the file is written: staging it and deleting it
        // afterwards would still put the bytes on the disk.
        if ($size > self::MAX_STAGED_BYTES) {
            throw new OpenAIImageException(
                sprintf(
                    'The input image is %s MB, exceeds the 25 MB cap. Resize the image before passing it as input_image.',
                    number_format($size / 1024 / 1024, 1),
                ),
                $this->timeoutSeconds,
            );
        }

        $format = self::imageFormat($bytes);
        $path = sys_get_temp_dir() . '/spora_openai_image_' . bin2hex(random_bytes(8)) . '.' . $format['extension'];

        $written = @file_put_contents($path, $bytes);
        $stream = $written === strlen($bytes) ? fopen($path, 'rb', false, stream_context_create([
            'http' => ['content_type' => $format['mime']],
        ])) : false;
        if ($stream === false) {
            @unlink($path);
            throw new OpenAIImageException(self::STAGE_FAILED, $this->timeoutSeconds);
        }

        return ['stream' => $stream, 'path' => $path];
    }

    /**
     * The upstream accepts PNG, JPEG and WebP and reads the format off the
     * upload. The bytes are sniffed here, because the staged file is what
     * carries it — a JPEG staged as `.png` goes up as `image/png` and is
     * rejected.
     *
     * PNG is the fallback, which is what the plugin's own media archive
     * holds; the upstream then rejects a format it cannot read.
     *
     * @return array{extension: string, mime: string}
     */
    private static function imageFormat(string $bytes): array
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ['extension' => 'jpg', 'mime' => 'image/jpeg'];
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return ['extension' => 'webp', 'mime' => 'image/webp'];
        }

        return ['extension' => 'png', 'mime' => 'image/png'];
    }

    /**
     * @param array{stream: resource, path: string} $image
     */
    private function rewind(array $image): void
    {
        if (is_resource($image['stream'])) {
            rewind($image['stream']);
        }
    }

    /**
     * @param array{stream: resource, path: string} $image
     */
    private function discardStagedImage(array $image): void
    {
        if (is_resource($image['stream'])) {
            fclose($image['stream']);
        }
        @unlink($image['path']);
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
            throw new OpenAIImageException($this->errorMessage($response->decoded, $response->status, $response->path), $this->timeoutSeconds);
        }

        if (!is_array($response->decoded)) {
            throw new OpenAIImageException('Image API returned a non-JSON response.', $this->timeoutSeconds);
        }

        return $response->decoded;
    }

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status, string $path): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Image API returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }

        // The path is the only clue when the upstream answers with an empty
        // body — a bare status says nothing about which endpoint was called.
        return 'Image API returned HTTP ' . $status . ($path === '' ? '' : ' on ' . $path) . '.';
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
        public readonly string $path = '',
    ) {}
}
