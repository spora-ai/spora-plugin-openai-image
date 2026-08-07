<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the OpenAI-compatible
 * image endpoints.
 *
 * Single-shot: every failure surfaces to the caller so the LLM can adapt on retry
 * (lower quality, smaller size, raise `http_timeout_seconds`). An internal retry
 * loop on timeouts would just re-hit the same per-request cap and waste quota.
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
     * POST /v1/images/generations — text-to-image. Always produces one image
     * per call (the LLM-facing schema does not expose `n` here; multi-image
     * work goes through {@see self::generateVariations()}).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . '/images/generations';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                'Image API request failed: ' . $e->getMessage()
                . " — try a smaller size or lower quality, or ask the operator to raise http_timeout_seconds (current: {$this->timeoutSeconds}s).",
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            throw new RuntimeException($this->errorMessage($decoded, $status));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Image API returned a non-JSON response.');
        }

        return $decoded;
    }

    /**
     * POST /v1/images/generations (prompt-based) or /v1/images/variations
     * (image-based, multipart upload). The `input_image` key is the switch:
     * absent → JSON generation; present → multipart upload to the variations endpoint.
     *
     * @param array<string, mixed> $body Must include `n` (defaults are applied upstream).
     * @return array<string, mixed>
     */
    public function generateVariations(array $body): array
    {
        $inputImage = $body['input_image'] ?? null;
        unset($body['input_image']);

        if (is_string($inputImage) && $inputImage !== '') {
            return $this->uploadVariations($body, $inputImage);
        }

        return $this->generate($body);
    }

    /**
     * Multipart upload to /v1/images/variations. The upstream API only accepts
     * `image` (file), `n`, and `size` here — `prompt`, `quality`, and `background`
     * are not supported on the variations endpoint and are stripped if present.
     *
     * @param array<string, mixed> $body
     */
    private function uploadVariations(array $body, string $inputImage): array
    {
        $url = rtrim($this->baseUrl, '/') . '/images/variations';
        $multipart = [
            ['name' => 'image', 'contents' => $this->fetchImage($inputImage), 'filename' => 'input.png'],
        ];

        foreach (['n', 'size'] as $field) {
            if (isset($body[$field]) && $body[$field] !== '' && $body[$field] !== 'auto') {
                $multipart[] = ['name' => $field, 'contents' => (string) $body[$field]];
            }
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'multipart' => $multipart,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                'Image API request failed: ' . $e->getMessage()
                . " — try a smaller size, lower n, or ask the operator to raise http_timeout_seconds (current: {$this->timeoutSeconds}s).",
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            throw new RuntimeException($this->errorMessage($decoded, $status));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Image API returned a non-JSON response.');
        }

        return $decoded;
    }

    /**
     * Resolve an `input_image` reference to raw bytes. Accepts:
     *   - http(s)://... — fetches the URL via the injected HttpClient.
     *   - data:image/...;base64,... — decodes the base64 payload.
     *   - a Media Archive asset URL (`/api/v1/assets/<token>.<ext>`) — fetches it.
     */
    private function fetchImage(string $inputImage): string
    {
        if (str_starts_with($inputImage, 'data:')) {
            $comma = strpos($inputImage, ',');
            if ($comma === false) {
                throw new RuntimeException('input_image data URI is malformed.');
            }
            $payload = substr($inputImage, $comma + 1);
            $bytes = base64_decode($payload, true);
            if ($bytes === false) {
                throw new RuntimeException('input_image data URI is not valid base64.');
            }
            return $bytes;
        }

        if (preg_match('#^https?://#i', $inputImage) === 1) {
            try {
                $response = $this->httpClient->request('GET', $inputImage, [
                    'timeout' => $this->timeoutSeconds,
                ]);
            } catch (TransportExceptionInterface $e) {
                throw new RuntimeException('Failed to fetch input_image: ' . $e->getMessage(), 0, $e);
            }
            $status = $response->getStatusCode();
            $bytes = $response->getContent(false);
            if ($status >= 400) {
                throw new RuntimeException("Failed to fetch input_image: HTTP {$status}.");
            }
            return $bytes;
        }

        throw new RuntimeException(
            'input_image must be an http(s) URL or a data: URI; got an unrecognised value.',
        );
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
