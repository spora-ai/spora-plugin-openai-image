<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolve Spora Media Archive UUIDs and opaque `/api/v1/assets/<uuid>.<ext>`
 * URLs in the `input_image` argument of a `generate_variations` call into a
 * forwardable form before the HTTP client fetches the bytes.
 *
 * Pattern: the LLM calls `image_openai(action: "generate_variations",
 * input_image: "<uuid>")` after discovering a Media Archive asset via a
 * previous `generate` call (whose `image_urls[]` are `/api/v1/assets/<token>.<ext>`).
 * The asset URL or UUID is then the only thing the LLM has to feed forward
 * — the actual bytes never enter the chat context. The resolver reads the
 * bytes server-side, base64-encodes them, and replaces the argument with a
 * `data:` URI in the same slot.
 *
 * Three resolved forms:
 *   - `data_url` (DB BLOB) → `data:<mime>;base64,<payload>` (inlined)
 *   - `local` (disk)       → `data:<mime>;base64,<payload>` (loaded + encoded)
 *   - `external` (CDN)     → the original `source_url` (forwarded as-is;
 *                            the HTTP client fetches it server-side)
 *
 * For UUIDs that don't exist or aren't accessible to the caller, the resolver
 * returns a failed `ToolResult` with an LLM-actionable message — surfacing the
 * failure is the whole point: the LLM can self-correct (regenerate, paste a
 * public URL, …) without a retry.
 *
 * Size cap: 25 MB on the resulting `data:` URI. The OpenAI `/v1/images/variations`
 * endpoint accepts PNG inputs up to 4 MB; the resolver caps at 25 MB to leave
 * headroom for endpoints that are more permissive, while still rejecting
 * runaway inputs before the upstream charges for them.
 *
 * The reader is taken as a closure rather than a concrete service class so
 * the plugin can ship without depending on the `MediaAssetReader` type
 * directly (the host application's `MediaAssetReader` is `final` and
 * therefore not Mockery-friendly from a plugin test). The plugin's
 * {@see \Spora\Plugins\OpenAIImage\OpenAIImagePlugin::onContainerBuilding()} wraps the
 * core service in a one-line closure.
 */
final class OpenAIImageMediaArchiveResolver
{
    /**
     * 36-char UUID with optional `.<ext>` suffix, optionally prefixed by
     * `/api/v1/assets/`. The optional prefix is non-capturing so `$uuid`
     * always holds the bare 36-char id without the leading path.
     */
    private const UUID_REGEX = '#^(?:/api/v1/assets/)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\.[A-Za-z0-9]+)?$#i';

    private const MAX_DATA_URI_BYTES = 25 * 1024 * 1024;

    /**
     * @param Closure(string $id, ?int $userId): ?array $reader
     */
    public function __construct(
        private readonly Closure $reader,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Scan a single `input_image` argument and replace any Media Archive UUID
     * or opaque URL with an inline `data:` URI (or forward the `source_url`
     * for external storage).
     *
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    public function resolveInputImage(string $inputImage, ?int $userId): array
    {
        $uuid = $this->extractUuid($inputImage);
        if ($uuid === null) {
            // Not a Media Archive UUID or opaque URL — pass through. The
            // HTTP client will validate that it's http(s) or data:/ before
            // sending the request.
            return ['resolved' => $inputImage];
        }

        $result = ($this->reader)($uuid, $userId);
        if ($result === null) {
            return ['failed' => $this->notFoundFailure($inputImage)];
        }

        $this->logger?->debug('openai-image.media-archive-resolved', [
            'input'  => $inputImage,
            'uuid'   => $uuid,
            'status' => $result['status'] ?? 'unknown',
            'size'   => isset($result['bytes']) ? strlen($result['bytes']) : null,
        ]);

        return match ($result['status']) {
            'data_url', 'local' => $this->wrapAsDataUri($inputImage, $result['bytes'], $result['mime']),
            'external'         => ['resolved' => (string) $result['sourceUrl']],
            default            => ['failed' => $this->notFoundFailure($inputImage)],
        };
    }

    private function extractUuid(string $url): ?string
    {
        return preg_match(self::UUID_REGEX, $url, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    private function wrapAsDataUri(string $inputImage, string $bytes, string $mime): array
    {
        $rawBytes = strlen($bytes);
        $maxBytesUnderCap = (int) ((self::MAX_DATA_URI_BYTES - strlen('data:image/webp;base64,')) * 3 / 4);
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);

        if ($rawBytes > $maxBytesUnderCap || strlen($dataUri) > self::MAX_DATA_URI_BYTES) {
            return ['failed' => $this->sizeExceededFailure($inputImage, $rawBytes)];
        }

        return ['resolved' => $dataUri];
    }

    private function notFoundFailure(string $inputImage): ToolResult
    {
        return new ToolResult(false, sprintf(
            "input_image %s looks like a Media Archive reference but the asset was not found "
                . 'or is not accessible to the current user. Verify the UUID, or paste a public URL.',
            $inputImage,
        ));
    }

    private function sizeExceededFailure(string $inputImage, int $rawBytes): ToolResult
    {
        return new ToolResult(false, sprintf(
            "Media asset %s is %s MB, exceeds the 25 MB data URI cap. "
                . 'Resize the image before passing it as input_image, or use a public URL.',
            $inputImage,
            number_format($rawBytes / 1024 / 1024, 1),
        ));
    }
}
