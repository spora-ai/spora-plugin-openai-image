<?php

declare(strict_types=1);

use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Reference;
use Psr\Log\LoggerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\OpenAIImage\OpenAIImagePlugin;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Pull the array of raw definitions the listener just appended to the
 * builder via addDefinitions(). Each entry's value is the unprocessed
 * helper the plugin handed in (an AutowireDefinitionHelper or a
 * Closure) — PHP-DI only normalises these once build() runs, so the
 * helpers are still inspectable here.
 *
 * @return array<string, mixed>
 */
function openaiImageDefinitions(): array
{
    $plugin  = new OpenAIImagePlugin();
    $builder = new DI\ContainerBuilder();

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber($plugin);
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $reflection = new ReflectionObject($builder);
    $sources    = $reflection->getProperty('definitionSources')->getValue($builder); // nosonar php:S3011 -- PHP-DI v8 exposes no public API to read raw definitionSources after addDefinitions() but before build(); reflection is the only way to assert the helpers the subscriber registered.

    return end($sources);
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

it('closure factory returns an OpenAIImageMediaArchiveResolver bound to the wrapped reader and logger', function () {
    $definitions = openaiImageDefinitions();

    /** @var Closure $closure */
    $closure = $definitions[OpenAIImageMediaArchiveResolver::class];

    expect($closure)->toBeInstanceOf(Closure::class);

    // `MediaAssetReader` is `final` in spora-core; we exercise the closure
    // without constructing the reader's real dependencies — its first
    // regex branch (`extractUuid`) returns null for a non-Media-Archive
    // URL, so the reader is never called and the original input is
    // returned through the closure's pass-through path.
    $reader = (new ReflectionClass(MediaAssetReader::class))->newInstanceWithoutConstructor(); // nosonar php:S3011 -- MediaAssetReader is final with a constructor that pulls real dependencies; the test only invokes readAsset() through the regex pass-through path, so the bypass is safe.

    $resolver = $closure($reader, null);

    expect($resolver)->toBeInstanceOf(OpenAIImageMediaArchiveResolver::class);

    $resolved = $resolver->resolveInputImage('https://example.com/seed.png', 7);

    expect($resolved)->toBe(['resolved' => 'https://example.com/seed.png']);
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
