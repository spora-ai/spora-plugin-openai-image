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
