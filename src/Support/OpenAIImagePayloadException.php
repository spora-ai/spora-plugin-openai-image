<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;

/**
 * Thrown when the upstream API returns a payload that the plugin can't process
 * (e.g. invalid base64 in a `b64_json` field). Sibling of {@see OpenAIImageException},
 * which wraps transport / HTTP failures — split out so callers can tell a
 * "the API rejected this" from a "the API returned garbage" failure.
 */
final class OpenAIImagePayloadException extends RuntimeException {}
