<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tests\Support;

use RuntimeException;
use Spora\Services\AssetStore;

/**
 * An {@see AssetStore} that always refuses. Stands in for an operator
 * misconfiguration (full disk, revoked quota) so the tool's ingest-failure
 * fallback can be asserted deliberately instead of by accident.
 */
final class FailingAssetStore implements AssetStore
{
    public function store(string $bytes, ?string $mime = null, ?string $filename = null): never
    {
        throw new RuntimeException('Asset store refused the payload.');
    }
}
