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
 * A 1x1 JPEG, base64-encoded. The plugin stages the upload under the
 * extension of the format the bytes really are, so the test needs bytes of
 * a format other than PNG — and CI runs without GD, so they are inlined
 * rather than rendered.
 */
const HTTP_CLIENT_TEST_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

/**
 * Build a client pointed at the test base URL/api key.
 * Per-op signatures vary — pull the less-common fields out via the $args spread.
 */
function imageHttpClient(HttpClientInterface $http, int $timeout = HTTP_CLIENT_TEST_TIMEOUT_S): OpenAIImageHttpClient
{
    return new OpenAIImageHttpClient($http, HTTP_CLIENT_TEST_API_KEY, HTTP_CLIENT_TEST_BASE_URL, $timeout);
}

/**
 * Snapshot the upload body while the request is being built: the staged
 * image file is removed as soon as the request returns, and the stream is
 * closed with it.
 *
 * @param array<string, mixed> $options
 * @return array{fields: list<string>, n: mixed, size: mixed, model: mixed, prompt: mixed, quality: mixed, background: mixed, filename: string, bytes: string, is_stream: bool}
 */
function imageUploadSnapshot(array $options): array
{
    $body = (array) ($options['body'] ?? []);
    $stream = $body['image'] ?? null;
    $path = is_resource($stream) ? (string) stream_get_meta_data($stream)['uri'] : '';

    return [
        'fields'     => array_keys($body),
        'n'          => $body['n'] ?? null,
        'size'       => $body['size'] ?? null,
        'model'      => $body['model'] ?? null,
        'prompt'     => $body['prompt'] ?? null,
        'quality'    => $body['quality'] ?? null,
        'background' => $body['background'] ?? null,
        'filename'   => $path === '' ? '' : basename($path),
        'bytes'      => $path === '' ? '' : (string) file_get_contents($path),
        'is_stream'  => is_resource($stream),
    ];
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

it('routes generateVariations with input_image to the multipart edits endpoint', function () {
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

    $upload = null;

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->with('GET', 'https://example.com/seed.png', M::any())
        ->andReturn($fetchResponse);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options) use (&$upload): bool {
            $upload = imageUploadSnapshot($options);
            return $method === 'POST'
                && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/edits';
        })
        ->andReturn($uploadResponse);

    $decoded = imageHttpClient($http)->generateVariations([
        'input_image' => 'https://example.com/seed.png',
        'model' => HTTP_CLIENT_TEST_MODEL,
        'prompt' => 'make it winter',
        'n' => 4,
        'size' => '1024x1024',
        'quality' => 'high',
        'background' => 'transparent',
    ]);

    expect($decoded['data'])->toHaveCount(2);

    expect($upload['fields'])->toBe(['image', 'model', 'prompt', 'n', 'size', 'quality', 'background'])
        ->and($upload['model'])->toBe(HTTP_CLIENT_TEST_MODEL)
        ->and($upload['prompt'])->toBe('make it winter')
        ->and($upload['n'])->toBe('4')
        ->and($upload['size'])->toBe('1024x1024')
        ->and($upload['quality'])->toBe('high')
        ->and($upload['background'])->toBe('transparent')
        ->and($upload['is_stream'])->toBeTrue()
        ->and($upload['filename'])->toEndWith('.png')
        ->and($upload['bytes'])->toBe('PNGDATA');
});

it('sends the edits upload as a body Symfony turns into multipart/form-data', function () {
    $uploadResponse = M::mock(ResponseInterface::class);
    $uploadResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $uploadResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('x')]],
    ]));

    $options = null;

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $requestOptions) use (&$options): bool {
            $options = $requestOptions;
            return true;
        })
        ->andReturn($uploadResponse);

    imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'n' => 2,
    ]);

    // Symfony's HttpClient has no `multipart` option — it rejects the
    // request outright. The image part has to be a stream inside `body`,
    // which is what flips the content type to multipart/form-data.
    expect($options)->not->toHaveKey('multipart')
        ->and($options['body'])->toBeArray()
        ->and($options['body']['image'])->toBeResource();
});

it('falls back to the retired variations endpoint when the provider has no edits endpoint', function () {
    $notFound = M::mock(ResponseInterface::class);
    $notFound->shouldReceive('getStatusCode')->once()->andReturn(404);
    $notFound->shouldReceive('getContent')->once()->with(false)->andReturn('');

    $legacyResponse = M::mock(ResponseInterface::class);
    $legacyResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $legacyResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('a')]],
    ]));

    $uploads = [];
    $positions = [];

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options) use (&$uploads, &$positions): bool {
            $uploads[$url] = imageUploadSnapshot($options);
            // Symfony drains the part's stream while it builds the body.
            stream_get_contents($options['body']['image']);
            $positions['edits'] = ftell($options['body']['image']);
            return $url === HTTP_CLIENT_TEST_BASE_URL . '/images/edits';
        })
        ->andReturn($notFound);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options) use (&$uploads, &$positions): bool {
            $uploads[$url] = imageUploadSnapshot($options);
            $positions['variations'] = ftell($options['body']['image']);
            return $url === HTTP_CLIENT_TEST_BASE_URL . '/images/variations';
        })
        ->andReturn($legacyResponse);

    $decoded = imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'model' => HTTP_CLIENT_TEST_MODEL,
        'prompt' => 'never sent',
        'n' => 2,
        'quality' => 'high',
    ]);

    expect($decoded['data'])->toHaveCount(1);

    // The retired endpoint took the image file, `n` and `size` and nothing
    // else.
    $legacy = $uploads[HTTP_CLIENT_TEST_BASE_URL . '/images/variations'];
    expect($legacy['fields'])->toBe(['image', 'n'])
        ->and($legacy['n'])->toBe('2')
        ->and($legacy['bytes'])->toBe('PNGBYTES');

    // The image has to reach the second request whole, so the stream is
    // rewound after the first one read it to the end.
    expect($positions['edits'])->toBeGreaterThan(0)
        ->and($positions['variations'])->toBe(0);
});

it('reports the edits endpoint when neither the edits nor the variations endpoint exists', function () {
    $notFound = M::mock(ResponseInterface::class);
    $notFound->shouldReceive('getStatusCode')->andReturn(404);
    $notFound->shouldReceive('getContent')->with(false)->andReturn('');

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->twice()->andReturn($notFound);

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'n' => 2,
    ]))->toThrow(RuntimeException::class, 'Image API returned HTTP 404 on /images/edits.');
});

it('stages the upload under the extension of the format the bytes are', function () {
    $uploadResponse = M::mock(ResponseInterface::class);
    $uploadResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $uploadResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('x')]],
    ]));

    $upload = null;

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options) use (&$upload): bool {
            $upload = imageUploadSnapshot($options);
            return true;
        })
        ->andReturn($uploadResponse);

    // A JPEG staged as `.png` would go up as `image/png` and be rejected by
    // the upstream, which reads the format from the part's content type.
    imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/jpeg;base64,' . HTTP_CLIENT_TEST_JPEG,
        'n' => 1,
    ]);

    expect($upload['filename'])->toEndWith('.jpg');
});

it('decodes data: URIs as input_image without making an HTTP request', function () {
    $uploadResponse = M::mock(ResponseInterface::class);
    $uploadResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $uploadResponse->shouldReceive('getContent')->once()->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => base64_encode('x')]],
    ]));

    $upload = null;

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->withArgs(function (string $method, string $url, array $options) use (&$upload): bool {
            $upload = imageUploadSnapshot($options);
            return $method === 'POST'
                && $url === HTTP_CLIENT_TEST_BASE_URL . '/images/edits';
        })
        ->andReturn($uploadResponse);
    $http->shouldNotReceive('request'); // no GET — the data: URI is local

    $decoded = imageHttpClient($http)->generateVariations([
        'input_image' => 'data:image/png;base64,' . base64_encode('PNGBYTES'),
        'n' => 2,
    ]);
    expect($decoded['data'])->toHaveCount(1);
    expect($upload['bytes'])->toBe('PNGBYTES');
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

it('does not retry a transport failure on the edits upload and points at n', function () {
    $fetch = M::mock(ResponseInterface::class);
    $fetch->shouldReceive('getStatusCode')->andReturn(200);
    $fetch->shouldReceive('getContent')->with(false)->andReturn('PNGDATA');

    $transport = new class ('Idle timeout reached for ".../images/edits".') extends RuntimeException implements TransportExceptionInterface {};

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
        ->toThrow(RuntimeException::class, 'Image API returned HTTP 429 on /images/generations.');
});

it('refuses to stage an input image over the 25 MB cap and leaves no staged file behind', function () {
    $fetchResponse = M::mock(ResponseInterface::class);
    $fetchResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
    $fetchResponse->shouldReceive('getContent')->once()->with(false)->andReturn(str_repeat('a', 25 * 1024 * 1024 + 1));

    $http = M::mock(HttpClientInterface::class);
    // Only the fetch: the upload must never be attempted, because the bytes
    // are refused before the file is written.
    $http->shouldReceive('request')
        ->once()
        ->with('GET', 'https://example.com/big.png', M::any())
        ->andReturn($fetchResponse);

    expect(fn() => imageHttpClient($http)->generateVariations([
        'input_image' => 'https://example.com/big.png',
        'n' => 1,
    ]))->toThrow(RuntimeException::class, 'exceeds the 25 MB cap');

    // Nothing over the cap may survive in the temp dir — the file is never
    // created, so the bytes never reach the disk.
    $strays = array_filter(
        glob(sys_get_temp_dir() . '/spora_openai_image_*') ?: [],
        static fn(string $path): bool => (int) filesize($path) >= 25 * 1024 * 1024,
    );

    expect($strays)->toBe([]);
});
