<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Support;

use RuntimeException;

/**
 * Thrown when the upstream API returns a payload the plugin can't process
 * (e.g. invalid base64 in a `b64_json` field). Split from
 * {@see OpenAIImageException}, which wraps transport / HTTP failures, so
 * callers can tell "the API rejected this" from "the API returned garbage".
 */
final class OpenAIImagePayloadException extends RuntimeException {}
