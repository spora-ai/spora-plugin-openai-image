<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use Psr\Log\LoggerInterface;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

abstract class OpenAIImageTool extends AbstractTool
{
    protected const PROVIDER = 'image';
    protected const QUALIFIED_NAME = 'openai-image:image';
    protected const DEFAULT_MODEL = 'gpt-image-1';
    protected const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    protected const TIMEOUT_SECONDS = 120;

    private ?LoggerInterface $logger;

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
        private ?MediaArchiveService $mediaArchive = null,
    ) {
        $this->logger = $logger;
    }

    public function setMediaArchive(?MediaArchiveService $mediaArchive): void
    {
        $this->mediaArchive = $mediaArchive;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function run(
        array $arguments,
        int $agentId,
        ?int $userId,
        callable $work,
    ): ToolResult {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        if ($apiKey === '') {
            return new ToolResult(false, 'OpenAI-compatible image API key is not configured for this agent.');
        }

        $baseUrl = is_string($settings['base_url'] ?? null) && trim($settings['base_url']) !== ''
            ? rtrim(trim($settings['base_url']), '/')
            : static::DEFAULT_BASE_URL;
        $timeout = is_numeric($settings['http_timeout_seconds'] ?? null) && (int) $settings['http_timeout_seconds'] > 0
            ? (int) $settings['http_timeout_seconds']
            : static::TIMEOUT_SECONDS;
        $model = is_string($settings['model'] ?? null) && trim($settings['model']) !== ''
            ? trim($settings['model'])
            : static::DEFAULT_MODEL;

        $client = new OpenAIImageHttpClient($this->httpClient, $apiKey, $baseUrl, $timeout);
        try {
            return $work($client, $settings, $model);
        } catch (Throwable $e) {
            $this->logger?->error('OpenAI-compatible image generation failed', ['exception' => $e]);
            return new ToolResult(false, 'Image generation failed: ' . $e->getMessage());
        }
    }

    protected function mediaArchive(): ?MediaArchiveService
    {
        return $this->mediaArchive;
    }
}
