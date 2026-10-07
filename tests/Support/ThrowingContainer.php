<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tests\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;

/**
 * Stand-in for the DI container {@see MediaDerivativeService} probes when it
 * resolves a registered {@see MediaDerivativeProducerInterface}. No producer is
 * registered in these tests, so a lookup is a programming error rather than
 * something to simulate.
 */
final class ThrowingContainer implements ContainerInterface
{
    public function get(string $id): never
    {
        throw new RuntimeException("Unexpected container lookup for '{$id}'.");
    }

    public function has(string $id): bool
    {
        return false;
    }
}
