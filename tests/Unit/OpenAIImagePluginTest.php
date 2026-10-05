<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Reference;
use Mockery as M;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\OpenAIImage\OpenAIImagePlugin;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
use Spora\Plugins\OpenAIImage\Tests\Support\InMemoryMediaArchive;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Bindings the plugin registers with PHP-DI's ContainerBuilder. Each
 * value is the unprocessed helper the plugin hands to
 * `addDefinitions()` (an `AutowireDefinitionHelper` or a `Closure`) —
 * PHP-DI only normalises these once `build()` runs, so the helpers are
 * still inspectable here.
 *
 * @return array<string, mixed>
 */
function openaiImageDefinitions(): array
{
    return (new OpenAIImagePlugin())->containerDefinitions();
}

it('returns the plugin name', function () {
    expect((new OpenAIImagePlugin())->getName())->toBe('OpenAI Image');
});

it('contributes the OpenAIImageGenerationTool', function () {
    expect((new OpenAIImagePlugin())->tools())->toBe([OpenAIImageGenerationTool::class]);
});

it('subscribes only to ContainerBuildingEvent via onContainerBuilding', function () {
    $events = OpenAIImagePlugin::getSubscribedEvents();

    expect($events)
        ->toHaveKey(ContainerBuildingEvent::class)
        ->and($events[ContainerBuildingEvent::class])->toBe('onContainerBuilding');
});

it('registers OpenAIImageHttpClient via autowire()', function () {
    $definitions = openaiImageDefinitions();

    expect($definitions[OpenAIImageHttpClient::class])->toBeInstanceOf(AutowireDefinitionHelper::class);
});

it('registers OpenAIImageMediaArchiveResolver via a closure factory', function () {
    $definitions = openaiImageDefinitions();

    expect($definitions[OpenAIImageMediaArchiveResolver::class])->toBeInstanceOf(Closure::class);
});

it('builds a resolver that reads Media Archive rows through the host reader', function () {
    $harness = InMemoryMediaArchive::boot();
    $harness->insertAsset(
        '0d4f3c70-1234-5678-9abc-deadbeef0000',
        'PNGBYTES',
        'image/png',
        'data_url',
    );

    $factory = openaiImageDefinitions()[OpenAIImageMediaArchiveResolver::class];
    $resolver = $factory($harness->reader(), new NullLogger());

    expect($resolver)->toBeInstanceOf(OpenAIImageMediaArchiveResolver::class)
        ->and($resolver->resolveInputImage('0d4f3c70-1234-5678-9abc-deadbeef0000', InMemoryMediaArchive::USER_ID))
        ->toBe(['resolved' => 'data:image/png;base64,' . base64_encode('PNGBYTES')]);
});

it('resolves an external row through the host reader', function () {
    $harness = InMemoryMediaArchive::boot();
    $harness->insertAsset(
        '0d4f3c70-1234-5678-9abc-deadbeef0000',
        'PNGBYTES',
        'image/png',
        'external',
        'https://cdn.example.com/seed.png',
    );

    $factory = openaiImageDefinitions()[OpenAIImageMediaArchiveResolver::class];
    $resolver = $factory($harness->reader(), new NullLogger());

    expect($resolver->resolveInputImage('0d4f3c70-1234-5678-9abc-deadbeef0000', InMemoryMediaArchive::USER_ID))
        ->toBe(['resolved' => 'https://cdn.example.com/seed.png']);
});

/**
 * `source_url` is a nullable column, so a row can claim `external` storage
 * while pointing nowhere. The host reader downgrades that to `null`, which
 * must surface as the resolver's not-found failure — never as an empty
 * `input_image` that the HTTP client then blames on the operator.
 */
it('fails an external row whose source_url is null instead of resolving an empty image', function () {
    $harness = InMemoryMediaArchive::boot();
    $harness->insertAsset(
        '0d4f3c70-1234-5678-9abc-deadbeef0000',
        'PNGBYTES',
        'image/png',
        'external',
    );

    $factory = openaiImageDefinitions()[OpenAIImageMediaArchiveResolver::class];
    $resolver = $factory($harness->reader(), new NullLogger());

    $raised = [];
    set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        $result = $resolver->resolveInputImage('0d4f3c70-1234-5678-9abc-deadbeef0000', InMemoryMediaArchive::USER_ID);
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBe([])
        ->and($result)->toHaveKey('failed')
        ->and($result['failed']->success)->toBeFalse()
        ->and($result['failed']->content)->toContain('0d4f3c70-1234-5678-9abc-deadbeef0000');
});

it('wires a tool that can generate a variation from a previously archived asset', function () {
    $harness = InMemoryMediaArchive::boot();
    $harness->insertAsset(
        '0d4f3c70-1234-5678-9abc-deadbeef0000',
        'PNGBYTES',
        'image/png',
        'data_url',
    );

    $config = M::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key' => 'sk-test',
        'base_url' => 'https://provider.example/v1',
    ]);

    $upload = M::mock(ResponseInterface::class);
    $upload->shouldReceive('getStatusCode')->andReturn(200);
    $upload->shouldReceive('getContent')->with(false)->andReturn(json_encode([
        'data' => [['b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==']],
    ]));

    $http = M::mock(HttpClientInterface::class);
    $http->shouldReceive('request')->andReturn($upload);

    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        ToolConfigService::class         => $config,
        HttpClientInterface::class       => $http,
        LoggerInterface::class           => new NullLogger(),
        MediaArchiveService::class       => $harness->service(),
        MediaAssetReader::class          => $harness->reader(),
    ]);
    (new OpenAIImagePlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    $tool = $builder->build()->get(OpenAIImageGenerationTool::class);

    $result = $tool->execute([
        'action'      => 'generate_variations',
        'input_image' => '0d4f3c70-1234-5678-9abc-deadbeef0000',
        'n'           => 2,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('ships the skill and agent-template directories that ship with the plugin', function () {
    $plugin = new OpenAIImagePlugin();

    expect($plugin->skillPaths())->toHaveCount(1)
        ->and(is_dir($plugin->skillPaths()[0]))->toBeTrue()
        ->and($plugin->agentTemplatePaths())->toHaveCount(1)
        ->and(is_dir($plugin->agentTemplatePaths()[0]))->toBeTrue();
});

it('chained setters on OpenAIImageGenerationTool wire MediaArchiveService, the resolver, and LoggerInterface', function () {
    $definitions = openaiImageDefinitions();

    /** @var AutowireDefinitionHelper $helper */
    $helper = $definitions[OpenAIImageGenerationTool::class];

    expect($helper)->toBeInstanceOf(AutowireDefinitionHelper::class);

    $definition      = $helper->getDefinition(OpenAIImageGenerationTool::class);
    $methodInjections = $definition->getMethodInjections();

    $methodNames = array_map(static fn($i) => $i->getMethodName(), $methodInjections);

    expect($methodNames)->toContain('setMediaArchive', 'setMediaArchiveResolver', 'setLogger');

    foreach ($methodInjections as $injection) {
        $methodName = $injection->getMethodName();
        $parameters = $injection->getParameters();

        expect($parameters)->toHaveCount(1);
        $ref = $parameters[array_key_first($parameters)];

        expect($ref)->toBeInstanceOf(Reference::class);

        $referenced = match ($ref->getTargetEntryName()) {
            MediaArchiveService::class         => 'setMediaArchive',
            OpenAIImageMediaArchiveResolver::class => 'setMediaArchiveResolver',
            LoggerInterface::class             => 'setLogger',
            default                            => null,
        };

        expect($methodName)->toBe($referenced);
    }
});
