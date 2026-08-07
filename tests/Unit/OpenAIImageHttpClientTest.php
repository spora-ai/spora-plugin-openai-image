<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('posts an OpenAI-compatible generation request with bearer authentication', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(200);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('png')]],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options): bool {
        return $method === 'POST'
            && $url === 'https://provider.example/v1/images/generations'
            && $options['headers']['Authorization'] === 'Bearer secret'
            && $options['json']['model'] === 'gpt-image-1'
            && $options['json']['prompt'] === 'A lighthouse';
    })->andReturn($response);

    $client = new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30);

    expect($client->generate(['model' => 'gpt-image-1', 'prompt' => 'A lighthouse']))
        ->toHaveKey('data');
});

it('includes the upstream error message for HTTP failures', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(400);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'error' => ['message' => 'invalid model'],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn($response);

    expect(fn() => (new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30))
        ->generate(['model' => 'bad']))
        ->toThrow(RuntimeException::class, 'invalid model');
});

it('does not retry on a transport timeout and surfaces the actionable error', function () {
    $transport = new class ('Idle timeout reached for "https://api.openai.com/v1/images/generations".') extends RuntimeException implements TransportExceptionInterface {};

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->andThrow($transport);

    $caught = null;
    try {
        (new OpenAIImageHttpClient($http, 'secret', 'https://api.openai.com/v1', 600))
            ->generate(['model' => 'gpt-image-2', 'prompt' => 'x']);
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('Idle timeout')
        ->and($caught->getMessage())->toContain('http_timeout_seconds')
        ->and($caught->getMessage())->toContain('600s');
});
