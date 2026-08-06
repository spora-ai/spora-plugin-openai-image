<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
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
