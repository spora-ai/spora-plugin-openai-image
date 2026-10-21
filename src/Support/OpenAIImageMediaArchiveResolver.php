<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolve Spora Media Archive UUIDs and opaque `/api/v1/assets/<uuid>.<ext>`
 * URLs into a form the image API can fetch.
 *
 * The bytes never enter the chat context: the resolver reads them server-side
 * and either inlines them as a `data:` URI (`data_url` and `local` storage) or
 * forwards the CDN `source_url` (`external`). An unknown or inaccessible asset
 * yields a failed `ToolResult` with an actionable message, so the model can
 * self-correct instead of retrying.
 *
 * Size cap: 25 MB on the resulting `data:` URI. The OpenAI
 * `/v1/images/variations` endpoint accepts PNG inputs up to 4 MB; the cap
 * leaves headroom for more permissive endpoints while still rejecting runaway
 * inputs before the upstream charges for them.
 *
 * The reader arrives as a closure rather than a concrete service so the plugin
 * does not depend on the host's `final`
 * {@see \Spora\Services\MediaArchive\MediaAssetReader}.
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
     * or opaque URL with an inline `data:` URI, or with the `source_url` for
     * external storage.
     *
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    public function resolveInputImage(string $inputImage, ?int $userId): array
    {
        $uuid = $this->extractUuid($inputImage);
        if ($uuid === null) {
            // Not a Media Archive UUID or opaque URL — pass through. The HTTP
            // client rejects anything that is neither http(s) nor a data: URI.
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
