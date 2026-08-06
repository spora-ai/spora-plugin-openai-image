<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class OpenAIImageHttpClient
{
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];
    private const MAX_ATTEMPTS = 3;

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
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                    'timeout' => $this->timeoutSeconds,
                ]);
                $status = $response->getStatusCode();
                if ($attempt < self::MAX_ATTEMPTS && in_array($status, self::RETRYABLE_STATUS_CODES, true)) {
                    usleep($attempt * 250000);
                    continue;
                }
                $content = $response->getContent(false);
                $decoded = json_decode($content, true);
                if ($status >= 400) {
                    throw new RuntimeException($this->errorMessage($decoded, $status));
                }
                if (!is_array($decoded)) {
                    throw new RuntimeException('Image API returned a non-JSON response.');
                }
                return $decoded;
            } catch (TransportExceptionInterface $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new RuntimeException('Image API request failed: ' . $e->getMessage(), 0, $e);
                }
                usleep($attempt * 250000);
            } catch (Throwable $e) {
                throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            }
        }
        throw new RuntimeException('Image API request failed.');
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
