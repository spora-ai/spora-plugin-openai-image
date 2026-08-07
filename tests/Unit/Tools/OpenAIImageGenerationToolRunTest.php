<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\LoggerInterface;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Regression tests for OpenAIImageTool::run(). The runtime contract is:
 *   $work($client, $model)
 * — pass exactly two arguments. A previous version was passing
 *   $work($client, $settings, $model), which PHP positional-binding
 *   routed into the closure's `string $model` parameter (because the
 *   closures only declare two parameters), turning the array
 *   $settings into a TypeError on every call.
 */
function toolWith(
    array $settings,
    string $model = 'gpt-image-2',
): OpenAIImageGenerationTool {
    $config = M::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $http = M::mock(HttpClientInterface::class);
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('png')]],
    ]));
    $http->shouldReceive('request')->andReturn($response);

    $logger = M::mock(LoggerInterface::class);
    $logger->shouldIgnoreMissing();

    // MediaArchiveService is `final` and can't be mocked. Pass null and the
    // tool falls back to data URL ingestion, which is fine for these tests.
    $tool = new OpenAIImageGenerationTool($config, $http, $logger, null);
    return $tool;
}

it('does not typeerror when model is configured as a string (regression for the $settings/$model positional bug)', function () {
    $tool = toolWith([
        'api_key' => 'sk-test',
        'base_url' => 'https://api.openai.com/v1',
        'http_timeout_seconds' => 600,
        'model' => 'gpt-image-2',
    ]);

    $result = $tool->execute([
        'action' => 'generate',
        'prompt' => 'A lighthouse',
    ], agentId: 1, userId: 1);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('A lighthouse');
});

it('falls back to the default model when the configured value is empty', function () {
    $tool = toolWith([
        'api_key' => 'sk-test',
        'base_url' => 'https://api.openai.com/v1',
        'http_timeout_seconds' => 600,
        'model' => '',
    ]);

    $result = $tool->execute([
        'action' => 'generate',
        'prompt' => 'A lighthouse',
    ], agentId: 1, userId: 1);

    expect($result->success)->toBeTrue();
});

it('refuses to run when the api_key is missing', function () {
    $tool = toolWith([
        'api_key' => '',
        'base_url' => 'https://api.openai.com/v1',
        'http_timeout_seconds' => 600,
        'model' => 'gpt-image-2',
    ]);

    $result = $tool->execute([
        'action' => 'generate',
        'prompt' => 'A lighthouse',
    ], agentId: 1, userId: 1);

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('API key');
});

it('summarises a long prompt in the markdown image tag (no full prompt in the alt text)', function () {
    $tool = toolWith([
        'api_key' => 'sk-test',
        'base_url' => 'https://api.openai.com/v1',
        'http_timeout_seconds' => 600,
        'model' => 'gpt-image-2',
    ]);

    // 3500-char prompt — the same shape as the production infographic prompt that
    // bloated the rendering pipeline. The tool's `trim()` strips the trailing
    // space, so the assertion compares against the trimmed shape.
    $longPrompt = rtrim(str_repeat('A sprawling editorial infographic with cards, headers, and footers. ', 70));

    $result = $tool->execute([
        'action' => 'generate',
        'prompt' => $longPrompt,
    ], agentId: 1, userId: 1);

    expect($result->success)->toBeTrue();
    // The block must NOT contain the full prompt verbatim — the alt text bloats
    // markdown rendering and breaks strict parsers.
    expect($result->content)->not->toContain($longPrompt);
    // The full prompt stays on the data channel for callers that need it.
    expect($result->data['prompt'] ?? null)->toBe($longPrompt);
    // The alt text is short (just "Generated image N").
    $imageTag = explode("\n\n", $result->content)[1] ?? '';
    preg_match('/!\[([^\]]*)\]\(([^)]+)\)/', $imageTag, $m);
    expect(strlen($m[1] ?? ''))->toBeLessThanOrEqual(20);
});

it('does not break the markdown image tag when the prompt contains newlines and brackets', function () {
    $tool = toolWith([
        'api_key' => 'sk-test',
        'base_url' => 'https://api.openai.com/v1',
        'http_timeout_seconds' => 600,
        'model' => 'gpt-image-2',
    ]);

    $prompt = "Editorial infographic\nwith a [bracket] and a | pipe.";

    $result = $tool->execute([
        'action' => 'generate',
        'prompt' => $prompt,
    ], agentId: 1, userId: 1);

    expect($result->success)->toBeTrue();
    $imageTag = explode("\n\n", $result->content)[1] ?? '';
    expect($imageTag)->toMatch('/^!\[Generated image 1\]\([^)]+\)$/');
});
