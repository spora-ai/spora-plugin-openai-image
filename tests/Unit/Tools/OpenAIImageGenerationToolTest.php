<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\LoggerInterface;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
use Spora\Plugins\OpenAIImage\Tests\Support\InMemoryMediaArchive;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

const GEN_TOOL_BASE_URL = 'https://provider.example/v1';
const GEN_TOOL_UUID     = '0d4f3c70-1234-5678-9abc-deadbeef0000';

/** A real 1x1 PNG, base64-encoded the way an OpenAI-compatible API returns it in `b64_json`. */
const GEN_TOOL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const GEN_TOOL_DEFAULT_SETTINGS = [
    'api_key' => 'sk-test',
    'base_url' => GEN_TOOL_BASE_URL,
    'model'    => 'gpt-image-2',
];

/**
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $requests
 */
function genHttpRecording(array &$requests, mixed $body, int $status = 200): HttpClientInterface
{
    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->andReturnUsing(
        function (string $method, string $url, array $options) use (&$requests, $body, $status): ResponseInterface {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = M::mock(ResponseInterface::class);
            $response->shouldReceive('getStatusCode')->andReturn($status);
            $response->shouldReceive('getContent')->with(false)->andReturn(json_encode($body));
            return $response;
        },
    );
    return $http;
}

/** @param array{method: string, url: string, options: array<string, mixed>} $request */
function genJsonBody(array $request): array
{
    $body = $request['options']['json'] ?? null;
    expect($body)->toBeArray();

    return $body;
}

/** @param list<mixed> $data */
function genHttpImages(array $data): HttpClientInterface
{
    $requests = [];

    return genHttpRecording($requests, ['data' => $data]);
}

function genTool(
    HttpClientInterface $http,
    array $settings = [],
    ?MediaArchiveService $archive = null,
    ?LoggerInterface $logger = null,
): OpenAIImageGenerationTool {
    $config = M::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings + GEN_TOOL_DEFAULT_SETTINGS);

    return new OpenAIImageGenerationTool($config, $http, $logger, $archive);
}

function genResolver(Closure $reader): OpenAIImageMediaArchiveResolver
{
    return new OpenAIImageMediaArchiveResolver($reader);
}

/** Pull the row id back out of the opaque `/api/v1/assets/<id>.<ext>` URL the archive mints. */
function genAssetId(string $assetUrl): string
{
    return (string) preg_replace('/^\/api\/v1\/assets\/([0-9a-f-]{36})\.[a-z0-9]+$/i', '$1', $assetUrl);
}

it('describes a generate call by its prompt', function () {
    $tool = genTool(genHttpImages([]));

    expect($tool->describeAction(['prompt' => 'A lighthouse']))->toBe("Generate image for prompt: 'A lighthouse'");
});

it('describes a variations call by its prompt and truncates it to 80 characters', function () {
    $tool = genTool(genHttpImages([]));

    expect($tool->describeAction(['action' => 'generate_variations', 'prompt' => 'A lighthouse']))
        ->toBe("Generate image variations for prompt: 'A lighthouse'");

    expect($tool->describeAction(['prompt' => str_repeat('x', 200)]))->toBe(
        "Generate image for prompt: '" . str_repeat('x', 80) . "'",
    );
});

it('rejects a generate call with a blank prompt before touching the API', function () {
    $requests = [];
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = genTool($http)->execute(['action' => 'generate', 'prompt' => '   '], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe('Prompt cannot be empty.');
});

it('rejects a variations call that has neither a prompt nor an input image', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = genTool($http)->execute(['action' => 'generate_variations'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('requires either `prompt` or `input_image`');
});

it('clamps the variations count to the 2-8 range and defaults to 3 for a non-numeric value', function (mixed $given, int $expected) {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);

    $result = genTool($http)->execute([
        'action' => 'generate_variations',
        'prompt' => 'A lighthouse',
        'n'      => $given,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
    expect(genJsonBody($requests[0])['n'])->toBe($expected);
})->with([
    'non-numeric falls back to the default' => ['many', 3],
    'absent falls back to the default'      => [null, 3],
    'below the minimum is raised'           => [1, 2],
    'above the maximum is lowered'          => [99, 8],
    'in range is forwarded as-is'           => [5, 5],
]);

it('sends prompt-based variations to the generations endpoint with the resolved model', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);

    $result = genTool($http)->execute([
        'action' => 'generate_variations',
        'prompt' => 'A lighthouse',
        'n'      => 4,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($requests[0]['url'])->toBe(GEN_TOOL_BASE_URL . '/images/generations');

    expect(genJsonBody($requests[0]))->toMatchArray([
        'model'  => 'gpt-image-2',
        'prompt' => 'A lighthouse',
        'n'      => 4,
    ]);
});

it('uploads the input image to the variations endpoint instead of sending a model or prompt', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);

    $result = genTool($http)->execute([
        'action'      => 'generate_variations',
        'input_image' => 'data:image/png;base64,' . GEN_TOOL_PNG_BASE64,
        'n'           => 2,
        'prompt'      => 'A lighthouse',
    ], agentId: 1);

    expect($result->success)->toBeTrue();

    $upload = array_values(array_filter($requests, static fn(array $r): bool => $r['method'] === 'POST'));
    expect($upload)->toHaveCount(1)
        ->and($upload[0]['url'])->toBe(GEN_TOOL_BASE_URL . '/images/variations');

    $fields = [];
    foreach ($upload[0]['options']['multipart'] as $part) {
        $fields[$part['name']] = $part['contents'];
    }
    expect($fields)->not->toHaveKey('prompt')
        ->and($fields)->not->toHaveKey('model');
});

it('forwards size, quality and background only when they differ from auto', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);

    genTool($http)->execute([
        'action'     => 'generate',
        'prompt'     => 'A lighthouse',
        'size'       => '1024x1024',
        'quality'    => 'auto',
        'background' => 'transparent',
    ], agentId: 1);

    $body = genJsonBody($requests[0]);
    expect($body)->toMatchArray(['size' => '1024x1024', 'background' => 'transparent']);
    expect($body)->not->toHaveKey('quality');
});

it('fails when the API response carries no data array', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['created' => 1]);

    $result = genTool($http)->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe('Image API returned no images.');
});

it('fails when no returned item carries base64 image data', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['url' => 'https://cdn.example/a.png'], ['b64_json' => '']]]);

    $result = genTool($http)->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe('Image API returned no base64 image data.');
});

it('skips unusable items and archives the ones that carry base64 data', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [
        'not-an-array',
        ['b64_json' => GEN_TOOL_PNG_BASE64],
        ['url' => 'https://cdn.example/b.png'],
    ]]);

    $result = genTool($http)->execute([
        'action'    => 'generate_variations',
        'prompt'    => 'A lighthouse',
        'n'         => 2,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['image_urls'])->toHaveCount(1);
});

it('names a single archived image after the filename stem verbatim', function () {
    $archive = InMemoryMediaArchive::boot();
    $result = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]), [], $archive->service())
        ->execute(['prompt' => 'A lighthouse', 'filename' => ' cover '], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($archive->findAsset(genAssetId($result->data['image_urls'][0]))['filename'])->toBe('cover.png');
});

it('suffixes the filename stem per image for a multi-image call', function () {
    $archive = InMemoryMediaArchive::boot();
    $result = genTool(genHttpImages([
        ['b64_json' => GEN_TOOL_PNG_BASE64],
        ['b64_json' => GEN_TOOL_PNG_BASE64],
    ]), [], $archive->service())->execute([
        'action'   => 'generate_variations',
        'prompt'   => 'A lighthouse',
        'n'        => 2,
        'filename' => 'cover',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Generated 2 images')
        ->and($archive->findAsset(genAssetId($result->data['image_urls'][0]))['filename'])->toBe('cover-1.png')
        ->and($archive->findAsset(genAssetId($result->data['image_urls'][1]))['filename'])->toBe('cover-2.png');
});

it('falls back to a positional filename when no stem is supplied', function () {
    $archive = InMemoryMediaArchive::boot();
    $result = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]), [], $archive->service())
        ->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($archive->findAsset(genAssetId($result->data['image_urls'][0]))['filename'])->toBe('openai-image-1.png');
});

it('records the ingest request against the running agent and user', function () {
    $archive = InMemoryMediaArchive::boot();
    $result = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]), [], $archive->service())
        ->execute(
            ['prompt' => 'A lighthouse'],
            agentId: InMemoryMediaArchive::AGENT_ID,
            context: $archive->context(),
        );

    $row = $archive->findAsset(genAssetId($result->data['image_urls'][0]));

    expect($row['plugin_slug'])->toBe('openai-image')
        ->and($row['tool_name'])->toBe('image')
        ->and($row['prompt'])->toBe('A lighthouse')
        ->and((int) $row['agent_id'])->toBe(InMemoryMediaArchive::AGENT_ID)
        ->and((int) $row['user_id'])->toBe(InMemoryMediaArchive::USER_ID)
        ->and($row['mime_type'])->toBe('image/png');
});

it('fails the whole call when the API returns base64 that cannot be decoded', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => '!!! not base64 !!!']]]);

    $result = genTool($http)->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Image API returned invalid base64 image data.');
});

it('returns an inline data URI instead of an archive URL when the archive is absent', function () {
    $result = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]))
        ->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($result->data['image_urls'][0])->toStartWith('data:image/png;base64,');
});

it('still succeeds with an inline data URI when the archive ingest fails', function () {
    $harness = InMemoryMediaArchive::boot();

    $result = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]), [], $harness->serviceWithFailingStore())
        ->execute(['prompt' => 'A lighthouse'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Generated image')
        ->and($result->data['image_urls'][0])->toBe('data:image/png;base64,' . GEN_TOOL_PNG_BASE64);
});

it('stops archiving once setMediaArchive(null) detaches the service', function () {
    $archive = InMemoryMediaArchive::boot();
    $tool = genTool(genHttpImages([['b64_json' => GEN_TOOL_PNG_BASE64]]), [], $archive->service());

    $archived = $tool->execute(['prompt' => 'A lighthouse'], agentId: 1);
    expect($archived->data['image_urls'][0])->toStartWith('/api/v1/assets/');

    $tool->setMediaArchive(null);

    $inline = $tool->execute(['prompt' => 'A lighthouse'], agentId: 1);
    expect($inline->success)->toBeTrue()
        ->and($inline->data['image_urls'][0])->toStartWith('data:image/png;base64,');
});

it('leaves input_image untouched when no resolver is wired', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);

    $result = genTool($http)->execute([
        'action'      => 'generate_variations',
        'input_image' => GEN_TOOL_UUID,
        'n'           => 2,
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('http(s) URL or a data: URI');
});

it('rewrites a resolved Media Archive reference into a data URI before uploading it', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);
    $tool = genTool($http);
    $tool->setMediaArchiveResolver(genResolver(static fn(string $id, ?int $userId): array => [
        'status' => 'data_url',
        'bytes'  => 'PNGBYTES',
        'mime'   => 'image/png',
    ]));

    $result = $tool->execute([
        'action'      => 'generate_variations',
        'input_image' => GEN_TOOL_UUID,
        'n'           => 2,
    ], agentId: 1);

    expect($result->success)->toBeTrue();

    $upload = array_values(array_filter($requests, static fn(array $r): bool => $r['method'] === 'POST'));
    expect($upload[0]['options']['multipart'][0]['contents'])->toBe('PNGBYTES');
});

it('forwards the resolved source URL of an externally stored asset', function () {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);
    $tool = genTool($http);
    $tool->setMediaArchiveResolver(genResolver(static fn(): array => [
        'status'    => 'external',
        'sourceUrl' => 'https://cdn.example/seed.png',
    ]));

    $result = $tool->execute([
        'action'      => 'generate_variations',
        'input_image' => GEN_TOOL_UUID,
        'n'           => 2,
    ], agentId: 1);

    $fetch = array_values(array_filter($requests, static fn(array $r): bool => $r['method'] === 'GET'));
    expect($result->success)->toBeTrue()
        ->and($fetch[0]['url'])->toBe('https://cdn.example/seed.png');
});

it('returns the resolver failure to the caller without calling the API', function () {
    $http = M::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $tool = genTool($http);
    $tool->setMediaArchiveResolver(genResolver(static fn(): ?array => null));

    $result = $tool->execute([
        'action'      => 'generate_variations',
        'input_image' => GEN_TOOL_UUID,
        'n'           => 2,
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not found');
});

it('skips the resolver when input_image is absent or blank', function (?string $inputImage) {
    $requests = [];
    $http = genHttpRecording($requests, ['data' => [['b64_json' => GEN_TOOL_PNG_BASE64]]]);
    $tool = genTool($http);
    $tool->setMediaArchiveResolver(genResolver(static function (): array {
        throw new LogicException('the reader must not be consulted for a blank input_image');
    }));

    $result = $tool->execute([
        'action'      => 'generate_variations',
        'prompt'      => 'A lighthouse',
        'input_image' => $inputImage,
        'n'           => 2,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
})->with([
    'absent' => [null],
    'empty'  => [''],
]);
