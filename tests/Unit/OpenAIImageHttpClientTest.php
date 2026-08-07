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

it('routes generateVariations without input_image to the generations endpoint', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(200);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [
            ['b64_json' => base64_encode('a')],
            ['b64_json' => base64_encode('b')],
            ['b64_json' => base64_encode('c')],
        ],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options): bool {
        return $method === 'POST'
            && $url === 'https://provider.example/v1/images/generations'
            && $options['json']['n'] === 3
            && $options['json']['prompt'] === 'A lighthouse';
    })->andReturn($response);

    $client = new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30);

    $decoded = $client->generateVariations(['prompt' => 'A lighthouse', 'n' => 3]);
    expect($decoded['data'])->toHaveCount(3);
});

it('routes generateVariations with input_image to the multipart variations endpoint', function () {
    $uploadResponse = M::mock(ResponseInterface::class);
    $uploadResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $uploadResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [
            ['b64_json' => base64_encode('a')],
            ['b64_json' => base64_encode('b')],
        ],
    ]));

    $fetchResponse = M::mock(ResponseInterface::class);
    $fetchResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $fetchResponse->shouldReceive('getContent')->once()->with(false)->andReturn('PNGDATA');

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->with('GET', 'https://example.com/seed.png', M::any())
        ->andReturn($fetchResponse);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options): bool {
            return $method === 'POST'
                && $url === 'https://provider.example/v1/images/variations'
                && isset($options['multipart'])
                && count($options['multipart']) === 3
                && $options['multipart'][0]['name'] === 'image'
                && $options['multipart'][0]['contents'] === 'PNGDATA'
                && $options['multipart'][0]['filename'] === 'input.png'
                && $options['multipart'][1]['name'] === 'n'
                && $options['multipart'][1]['contents'] === '4'
                && $options['multipart'][2]['name'] === 'size'
                && $options['multipart'][2]['contents'] === '1024x1024';
        })
        ->andReturn($uploadResponse);

    $client = new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30);

    $decoded = $client->generateVariations([
        'input_image' => 'https://example.com/seed.png',
        'n' => 4,
        'size' => '1024x1024',
        // The upstream variations endpoint does not accept these — they must be
        // stripped from the multipart body, not just ignored.
        'prompt' => 'never sent',
        'quality' => 'high',
        'background' => 'transparent',
    ]);

    expect($decoded['data'])->toHaveCount(2);
});

it('decodes data: URIs as input_image without making an HTTP request', function () {
    $uploadResponse = M::mock(ResponseInterface::class);
    $uploadResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $uploadResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('x')]],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options): bool {
            return $method === 'POST'
                && $url === 'https://provider.example/v1/images/variations'
                && $options['multipart'][0]['contents'] === 'PNGBYTES';
        })
        ->andReturn($uploadResponse);
    $http->shouldNotReceive('request'); // no GET — the data: URI is local

    $client = new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30);
    $decoded = $client->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'n' => 2,
    ]);
    expect($decoded['data'])->toHaveCount(1);
});

it('rejects an unrecognised input_image scheme', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $client = new OpenAIImageHttpClient($http, 'secret', 'https://provider.example/v1', 30);
    expect(fn() => $client->generateVariations([
        'input_image' => '/local/path/to/file.png',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'http(s) URL or a data: URI');
});
