<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage\Tests\Support;

use RuntimeException;

/**
 * Test-only marker for "the reflection helper did not find what we expected".
 * Lets test helpers throw a typed exception instead of RuntimeException so
 * SonarQube's S112 generic-exception rule stays happy without weakening the
 * assertion semantics.
 */
final class AttributeNotFoundException extends RuntimeException {}
