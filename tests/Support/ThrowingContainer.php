<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tests\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Stand-in for the DI container the media-converter registry probes. No
 * converter is registered in these tests, so a lookup is a programming
 * error rather than something to simulate.
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
