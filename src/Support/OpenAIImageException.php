<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;
use Throwable;

/**
 * Raised by {@see OpenAIImageHttpClient} on every upstream failure
 * (transport timeout, non-2xx HTTP, non-JSON body). Carries the
 * current `http_timeout_seconds` so the error message can point the
 * LLM at the operator-configurable setting.
 */
final class OpenAIImageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $timeoutSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
