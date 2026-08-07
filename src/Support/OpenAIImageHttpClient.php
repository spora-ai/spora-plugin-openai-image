<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the OpenAI-compatible
 * `/images/generations` endpoint.
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

    /** @param array<string, mixed> $body */
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
            // Surface the timeout the operator can act on; the LLM chooses how to retry.
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

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Image API returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }
        return 'Image API returned HTTP ' . $status . '.';
    }
}
