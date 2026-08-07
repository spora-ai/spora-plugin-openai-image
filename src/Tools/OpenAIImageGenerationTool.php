<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tools;

use RuntimeException;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
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
    description: 'Generate images from a text prompt using an OpenAI-compatible image API. Two operations: `generate` (single image, no approval) and `generate_variations` (multiple images or semantic variations of an input image, approval-gated by default).',
    displayName: 'OpenAI Image',
    category: 'generation',
    icon: 'image',
)]
#[ToolOperation(name: 'generate', description: 'Generate a single image from a text prompt. Always one image — use `generate_variations` for multiple.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'generate_variations', description: 'Generate a small set of variations (3 by default, up to 8) from a prompt, or produce semantic variations of an existing image when `input_image` is supplied. Approval-gated by default — the LLM must ask the operator before issuing this call.', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the configured image API.', required: true)]
#[ToolSetting(key: 'base_url', label: 'API URL', type: 'text', description: 'OpenAI-compatible API base URL. The images endpoint is appended automatically.', default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Image model identifier.', default: 'gpt-image-2')]
#[ToolSetting(key: 'http_timeout_seconds', label: 'HTTP timeout (s)', type: 'number', description: 'Per-request timeout. Default 600 seconds. Lower if you only need single-image drafts; raise above 600 if `generate_variations(n: 8)` at high quality still hits the idle timeout.', default: '600')]
#[ToolParameter(name: 'prompt', type: 'string', description: 'The text prompt describing the image to generate. Required for `generate`. For `generate_variations`, required unless `input_image` is supplied.', required: ['generate'], maximum: 32000)]
#[ToolParameter(name: 'input_image', type: 'string', description: 'Source image for image-based variations. Accepts an http(s) URL, a `data:` URI, a 36-char Media Archive UUID, or an opaque `/api/v1/assets/<uuid>.<ext>` URL. When supplied, `generate_variations` POSTs to `/v1/images/variations` (multipart upload) instead of `/v1/images/generations` — useful for semantic variations of an existing image. Only meaningful for `generate_variations`.', required: false, maximum: 4096)]
#[ToolParameter(name: 'n', type: 'integer', description: 'Number of variation images to produce. Only applied by `generate_variations`. Default 3, minimum 2, maximum 8. The upstream API charges per image produced.', required: false, minimum: 2, maximum: 8, default: 3)]
#[ToolParameter(name: 'size', type: 'string', description: 'Generated image dimensions.', required: false, enum: ['auto', '1024x1024', '1536x1024', '1024x1536'], default: 'auto')]
#[ToolParameter(name: 'quality', type: 'string', description: 'Image quality. Ignored by `generate_variations` when `input_image` is supplied (the upstream variations endpoint does not support it).', required: false, enum: ['auto', 'low', 'medium', 'high'], default: 'auto')]
#[ToolParameter(name: 'background', type: 'string', description: 'Background mode. Ignored by `generate_variations` when `input_image` is supplied (the upstream variations endpoint does not support it).', required: false, enum: ['auto', 'opaque', 'transparent'], default: 'auto')]
#[ToolParameter(name: 'filename', type: 'string', description: 'Optional human-readable filename stem without an extension. The correct file extension is appended automatically. For variations, a `-1`, `-2`, … suffix is added per image.', required: false, maximum: 120)]
final class OpenAIImageGenerationTool extends OpenAIImageTool
{
    private const DEFAULT_N = 3;
    private const MAX_N = 8;
    private const MIN_N = 2;

    private ?OpenAIImageMediaArchiveResolver $mediaArchiveResolver = null;

    /**
     * Wired by PHP-DI from {@see OpenAIImagePlugin::register()}.
     * Optional: tools that don't take image inputs (none today) skip this.
     */
    public function setMediaArchiveResolver(?OpenAIImageMediaArchiveResolver $resolver): void
    {
        $this->mediaArchiveResolver = $resolver;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $arguments = $this->resolveInputImage($arguments, $userId);
        if ($arguments instanceof ToolResult) {
            return $arguments;
        }

        $operation = (string) ($arguments['action'] ?? 'generate');
        return match ($operation) {
            'generate_variations' => $this->generateVariations($arguments, $agentId, $userId),
            default              => $this->generate($arguments, $agentId, $userId),
        };
    }

    /**
     * Resolve a Media Archive reference in `input_image` before the
     * per-operation closures capture `$arguments`. The `fn()` closure
     * inside `run()` captures `$arguments` by value at definition time,
     * so the resolver must run at the entry point — not inside the
     * per-operation method — to ensure the rewritten URL reaches the
     * HTTP client.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    private function resolveInputImage(array $arguments, ?int $userId): array|ToolResult
    {
        if ($this->mediaArchiveResolver === null) {
            return $arguments;
        }
        $raw = $arguments['input_image'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return $arguments;
        }
        $outcome = $this->mediaArchiveResolver->resolveInputImage($raw, $userId);
        if (isset($outcome['failed'])) {
            return $outcome['failed'];
        }
        $arguments['input_image'] = $outcome['resolved'];
        return $arguments;
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'generate');
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);

        return match ($operation) {
            'generate_variations' => "Generate image variations for prompt: '{$prompt}'",
            default              => "Generate image for prompt: '{$prompt}'",
        };
    }

    /** @param array<string, mixed> $arguments */
    private function generate(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }

        return $this->run($arguments, $agentId, $userId, function (OpenAIImageHttpClient $client, array $settings, string $model) use ($arguments, $prompt, $agentId, $userId): ToolResult {
            $body = ['model' => $model, 'prompt' => $prompt];
            foreach (['size', 'quality', 'background'] as $field) {
                if (isset($arguments[$field]) && (string) $arguments[$field] !== 'auto') {
                    $body[$field] = (string) $arguments[$field];
                }
            }
            $response = $client->generate($body);
            return $this->renderResponse($response, $prompt, $arguments, $agentId, $userId, $model, 1);
        });
    }

    /** @param array<string, mixed> $arguments */
    private function generateVariations(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $inputImage = isset($arguments['input_image']) && is_string($arguments['input_image'])
            ? trim($arguments['input_image'])
            : '';

        if ($prompt === '' && $inputImage === '') {
            return new ToolResult(false, 'generate_variations requires either `prompt` or `input_image`.');
        }

        $n = $this->clampN($arguments['n'] ?? self::DEFAULT_N);

        return $this->run($arguments, $agentId, $userId, function (OpenAIImageHttpClient $client, array $settings, string $model) use ($arguments, $prompt, $inputImage, $n, $agentId, $userId): ToolResult {
            $body = ['n' => $n];
            foreach (['size', 'quality', 'background'] as $field) {
                if (isset($arguments[$field]) && (string) $arguments[$field] !== 'auto') {
                    $body[$field] = (string) $arguments[$field];
                }
            }
            if ($inputImage === '') {
                // Prompt-based variations: stays on /v1/images/generations.
                $body['model'] = $model;
                $body['prompt'] = $prompt;
            } else {
                // Image-based variations: /v1/images/variations ignores model / quality / background upstream.
                $body['input_image'] = $inputImage;
            }

            $response = $client->generateVariations($body);
            return $this->renderResponse($response, $prompt !== '' ? $prompt : 'variations of input image', $arguments, $agentId, $userId, $model, $n);
        });
    }

    private function clampN(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_N;
        }
        $n = (int) $value;
        if ($n < self::MIN_N) {
            return self::MIN_N;
        }
        if ($n > self::MAX_N) {
            return self::MAX_N;
        }
        return $n;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $arguments
     */
    private function renderResponse(array $response, string $prompt, array $arguments, int $agentId, ?int $userId, string $model, int $expectedCount): ToolResult
    {
        $items = $response['data'] ?? null;
        if (!is_array($items) || $items === []) {
            return new ToolResult(false, 'Image API returned no images.');
        }

        $urls = [];
        $filenameStem = isset($arguments['filename']) && is_string($arguments['filename']) && trim($arguments['filename']) !== ''
            ? trim($arguments['filename'])
            : null;

        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_string($item['b64_json'] ?? null) || $item['b64_json'] === '') {
                continue;
            }
            $filename = $filenameStem !== null && $expectedCount > 1
                ? $filenameStem . '-' . ($index + 1)
                : $filenameStem;
            $urls[] = $this->archive($item['b64_json'], $prompt, $filename, $agentId, $userId, (int) $index);
        }
        if ($urls === []) {
            return new ToolResult(false, 'Image API returned no base64 image data.');
        }

        $count = count($urls);
        $heading = $count === 1
            ? "Generated image for prompt: \"{$prompt}\""
            : "Generated {$count} images for prompt: \"{$prompt}\"";
        $content = $heading . "\n\n";
        $content .= implode("\n\n", array_map(static fn(int $i, string $url): string => MediaEmbed::image($url, 'Generated image ' . ($i + 1) . ': ' . $prompt), array_keys($urls), $urls));
        $content .= "\n\nEcho the markdown image block above verbatim so the chat UI renders the image inline. For raw URLs, read ToolResult.data.image_urls.";

        return new ToolResult(true, $content, ['image_urls' => $urls, 'model' => $model]);
    }

    private function archive(string $base64, string $prompt, ?string $filename, int $agentId, ?int $userId, int $index): string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new RuntimeException('Image API returned invalid base64 image data.');
        }
        $archive = $this->mediaArchive();
        if (!$archive instanceof MediaArchiveService) {
            return 'data:image/png;base64,' . $base64;
        }
        $archiveFilename = $filename !== null
            ? $filename . '.png'
            : 'openai-image-' . ($index + 1) . '.png';
        try {
            $asset = $archive->ingest(new MediaIngestRequest(
                bytes: $bytes,
                mime: 'image/png',
                agentId: $agentId,
                userId: $userId,
                pluginSlug: 'openai-image',
                toolName: 'image',
                prompt: $prompt,
                filename: $archiveFilename,
            ));
            return (string) $asset->asset_url;
        } catch (Throwable) {
            return 'data:image/png;base64,' . $base64;
        }
    }
}
