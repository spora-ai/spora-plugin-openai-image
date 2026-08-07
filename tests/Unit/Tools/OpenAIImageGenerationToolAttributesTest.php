<?php

declare(strict_types=1);

use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Reflection-based assertions on the #[ToolParameter] / #[ToolOperation]
 * attributes declared on OpenAIImageGenerationTool. Mirrors the pattern in
 * `spora-plugin-minimax`'s per-op-required tests.
 */

function imageToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(OpenAIImageGenerationTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . OpenAIImageGenerationTool::class);
}

function imageToolOperationArgs(string $name): array
{
    $reflection = new ReflectionClass(OpenAIImageGenerationTool::class);
    foreach ($reflection->getAttributes(ToolOperation::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolOperation '{$name}' not declared on " . OpenAIImageGenerationTool::class);
}

it('binds prompt to the generate operation (hidden on generate_variations-only agents)', function () {
    expect(imageToolParameterArgs('prompt')['required'])->toBe(['generate']);
});

it('keeps n / input_image at required: false with sensible defaults', function () {
    $n = imageToolParameterArgs('n');
    expect($n['required'])->toBeFalse();
    expect($n['default'])->toBe(3);
    expect($n['minimum'])->toBe(2);
    expect($n['maximum'])->toBe(8);

    $inputImage = imageToolParameterArgs('input_image');
    expect($inputImage['required'])->toBeFalse();
});

it('keeps size / quality / background / filename at required: false', function () {
    foreach (['size', 'quality', 'background', 'filename'] as $name) {
        expect(imageToolParameterArgs($name)['required'])->toBeFalse();
    }
});

it('marks generate_variations as requires-approval-by-default', function () {
    expect(imageToolOperationArgs('generate_variations')['requiresApprovalByDefault'])->toBeTrue();
});

it('keeps generate as no-approval-by-default', function () {
    expect(imageToolOperationArgs('generate')['requiresApprovalByDefault'])->toBeFalse();
});
