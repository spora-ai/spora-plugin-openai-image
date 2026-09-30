<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

const HTTP_CLIENT_TEST_BASE_URL  = 'https://provider.example/v1';
const HTTP_CLIENT_TEST_API_KEY   = 'secret';
const HTTP_CLIENT_TEST_PROMPT    = 'A lighthouse';
const HTTP_CLIENT_TEST_MODEL     = 'gpt-image-1';
const HTTP_CLIENT_TEST_TIMEOUT_S = 30;

/**
 * Build a client pointed at the test base URL/api key.
 * Per-op signatures vary — pull the less-common fields out via the $args spread.
 */
function imageHttpClient(HttpClientInterface $http, int $timeout = HTTP_CLIENT_TEST_TIMEOUT_S): OpenAIImageHttpClient
{
    return new OpenAIImageHttpClient($http, HTTP_CLIENT_TEST_API_KEY, HTTP_CLIENT_TEST_BASE_URL, $timeout);
}

it('posts an OpenAI-compatible generation request with bearer authentication', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(200);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('png')]],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->withArgs(function (string $method, string $url, array $options): bool {
        return $method === 'POST'
            && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/generations'
            && $options['headers']['Authorization'] === 'Bearer ' . HTTP_CLIENT_TEST_API_KEY
            && $options['json']['model'] === HTTP_CLIENT_TEST_MODEL
            && $options['json']['prompt'] === HTTP_CLIENT_TEST_PROMPT;
    })->andReturn($response);

    expect(imageHttpClient($http)->generate(['model' => HTTP_CLIENT_TEST_MODEL, 'prompt' => HTTP_CLIENT_TEST_PROMPT]))
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

    expect(fn() => imageHttpClient($http)->generate(['model' => 'bad']))
        ->toThrow(RuntimeException::class, 'invalid model');
});

it('does not retry on a transport timeout and surfaces the actionable error', function () {
    $transport = new class ('Idle timeout reached for "https://api.openai.com/v1/images/generations".') extends RuntimeException implements TransportExceptionInterface {};

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->andThrow($transport);

    $caught = null;
    try {
        imageHttpClient($http, 600)->generate(['model' => 'gpt-image-2', 'prompt' => 'x']);
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
            && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/generations'
            && $options['json']['n'] === 3
            && $options['json']['prompt'] === HTTP_CLIENT_TEST_PROMPT;
    })->andReturn($response);

    $decoded = imageHttpClient($http)->generateVariations(['prompt' => HTTP_CLIENT_TEST_PROMPT, 'n' => 3]);
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
                && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/variations'
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

    $decoded = imageHttpClient($http)->generateVariations([
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
                && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/variations'
                && $options['multipart'][0]['contents'] === 'PNGBYTES';
        })
        ->andReturn($uploadResponse);
    $http->shouldNotReceive('request'); // no GET — the data: URI is local

    $decoded = imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'n' => 2,
    ]);
    expect($decoded['data'])->toHaveCount(1);
});

it('rejects an unrecognised input_image scheme', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => '/local/path/to/file.png',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'http(s) URL or a data: URI');
});

it('rejects a data: URI with no comma separator', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'data URI is malformed');
});

it('rejects a data: URI whose payload is not valid base64', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,!!! not base64 !!!',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'data URI is not valid base64');
});

it('surfaces a transport failure while fetching an http(s) input_image', function () {
    $transport = new class ('Could not resolve host.') extends RuntimeException implements TransportExceptionInterface {};

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->with('GET', 'https://example.com/seed.png', M::any())->andThrow($transport);

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'https://example.com/seed.png',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'Failed to fetch input_image: Could not resolve host.');
});

it('rejects an input_image URL that responds with an error status', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(404);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn('Not Found');

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->with('GET', 'https://example.com/seed.png', M::any())->andReturn($response);

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'https://example.com/seed.png',
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'Failed to fetch input_image: HTTP 404.');
});

it('does not retry a transport failure on the variations upload and points at n', function () {
    $fetch = M::mock(ResponseInterface::class);
    $fetch->shouldReceive('getStatusCode')->andReturn(200);
    $fetch->shouldReceive('getContent')->with(false)->andReturn('PNGDATA');

    $transport = new class ('Idle timeout reached for ".../images/variations".') extends RuntimeException implements TransportExceptionInterface {};

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->with('GET', 'https://example.com/seed.png', M::any())->andReturn($fetch);
    $http->shouldReceive('request')->once()->andThrow($transport);

    $caught = null;
    try {
        imageHttpClient($http, 90)->generateVariations([
            'input_image' => 'https://example.com/seed.png',
            'n' => 8,
        ]);
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('lower n')
        ->and($caught->getMessage())->toContain('90s');
});

it('rejects a 2xx response whose body is not JSON', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(200);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn('<html>gateway</html>');

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn($response);

    expect(fn() => imageHttpClient($http)->generate(['model' => HTTP_CLIENT_TEST_MODEL]))
        ->toThrow(RuntimeException::class, 'non-JSON response');
});

it('falls back to a bare status line when the error body carries no message', function () {
    $response = M::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->once()->andReturn(429);
    $response->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode(['error' => 'rate limited']));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn($response);

    expect(fn() => imageHttpClient($http)->generate(['model' => HTTP_CLIENT_TEST_MODEL]))
        ->toThrow(RuntimeException::class, 'Image API returned HTTP 429.');
});
