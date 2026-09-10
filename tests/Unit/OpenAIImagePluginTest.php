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

    // The host's `MediaAssetReader` is `final` and pulls real storage
    // dependencies from its constructor, so we cannot invoke the
    // registered closure factory from a plugin test without bypassing
    // its constructor. The factory's return type and pass-through
    // behaviour are covered by `OpenAIImageMediaArchiveResolverTest` —
    // here we only assert the binding shape the plugin hands to
    // PHP-DI.
    expect($definitions[OpenAIImageMediaArchiveResolver::class])->toBeInstanceOf(Closure::class);
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
