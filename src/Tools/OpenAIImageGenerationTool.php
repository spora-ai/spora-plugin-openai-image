<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tools;

use RuntimeException;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

#[Tool(
    name: 'image_openai',
    description: 'Generate an image from a text prompt using an OpenAI-compatible image API.',
    displayName: 'OpenAI Image',
    category: 'generation',
    icon: 'image',
)]
#[ToolOperation(name: 'generate', description: 'Generate an image from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the configured image API.', required: true)]
#[ToolSetting(key: 'base_url', label: 'API URL', type: 'text', description: 'OpenAI-compatible API base URL. The images endpoint is appended automatically.', default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Image model identifier.', default: 'gpt-image-2')]
#[ToolSetting(key: 'http_timeout_seconds', label: 'HTTP timeout (s)', type: 'number', description: 'Per-request timeout. Default 120 seconds.', default: '120')]
#[ToolParameter(name: 'prompt', type: 'string', description: 'The text prompt describing the image to generate.', required: true, maximum: 32000)]
#[ToolParameter(name: 'size', type: 'string', description: 'Generated image dimensions.', required: false, enum: ['auto', '1024x1024', '1536x1024', '1024x1536'], default: 'auto')]
#[ToolParameter(name: 'quality', type: 'string', description: 'Image quality.', required: false, enum: ['auto', 'low', 'medium', 'high'], default: 'auto')]
#[ToolParameter(name: 'background', type: 'string', description: 'Background mode.', required: false, enum: ['auto', 'opaque', 'transparent'], default: 'auto')]
#[ToolParameter(name: 'n', type: 'integer', description: 'Number of images to generate.', required: false, minimum: 1, maximum: 10, default: 1)]
#[ToolParameter(name: 'filename', type: 'string', description: 'Optional human-readable filename without an extension.', required: false, maximum: 120)]
final class OpenAIImageGenerationTool extends OpenAIImageTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }

        return $this->run($arguments, $agentId, $userId, function (OpenAIImageHttpClient $client, array $settings, string $model) use ($arguments, $prompt, $agentId, $userId): ToolResult {
            $body = ['model' => $model, 'prompt' => $prompt, 'n' => $this->positiveInt($arguments['n'] ?? 1, 1)];
            foreach (['size', 'quality', 'background'] as $field) {
                if (isset($arguments[$field]) && (string) $arguments[$field] !== 'auto') {
                    $body[$field] = (string) $arguments[$field];
                }
            }
            $response = $client->generate($body);
            $items = $response['data'] ?? null;
            if (!is_array($items) || $items === []) {
                return new ToolResult(false, 'Image API returned no images.');
            }

            $urls = [];
            foreach ($items as $index => $item) {
                if (!is_array($item) || !is_string($item['b64_json'] ?? null) || $item['b64_json'] === '') {
                    continue;
                }
                $urls[] = $this->archive($item['b64_json'], $prompt, $arguments, $agentId, $userId, (int) $index);
            }
            if ($urls === []) {
                return new ToolResult(false, 'Image API returned no base64 image data.');
            }

            $content = 'Generated ' . count($urls) . ' image' . (count($urls) === 1 ? '' : 's') . " for prompt: \"{$prompt}\"\n\n";
            $content .= implode("\n\n", array_map(static fn(int $i, string $url): string => MediaEmbed::image($url, 'Generated image ' . ($i + 1) . ': ' . $prompt), array_keys($urls), $urls));
            $content .= "\n\nEcho the markdown image block above verbatim so the chat UI renders the image inline. For raw URLs, read ToolResult.data.image_urls.";

            return new ToolResult(true, $content, ['image_urls' => $urls, 'model' => $model]);
        });
    }

    public function describeAction(array $arguments): string
    {
        return "Generate image for prompt: '" . mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80) . "'";
    }

    private function archive(string $base64, string $prompt, array $arguments, int $agentId, ?int $userId, int $index): string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new RuntimeException('Image API returned invalid base64 image data.');
        }
        $archive = $this->mediaArchive();
        if (!$archive instanceof MediaArchiveService) {
            return 'data:image/png;base64,' . $base64;
        }
        try {
            $asset = $archive->ingest(new MediaIngestRequest(
                bytes: $bytes,
                mime: 'image/png',
                agentId: $agentId,
                userId: $userId,
                pluginSlug: 'openai-image',
                toolName: 'image',
                prompt: $prompt,
                filename: 'openai-image-' . ($index + 1) . '.png',
            ));
            return (string) $asset->asset_url;
        } catch (Throwable) {
            return 'data:image/png;base64,' . $base64;
        }
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
