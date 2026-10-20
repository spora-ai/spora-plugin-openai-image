<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage;

use Psr\Log\LoggerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to {@see ContainerBuildingEvent} for the three DI bindings
 * php-di cannot autowire: the HTTP client, the media-archive resolver
 * (wraps {@see MediaAssetReader} as a closure so the plugin does not
 * couple to the host's `final` reader), and the generation tool's
 * method-injected dependencies.
 */
final class OpenAIImagePlugin extends AbstractPlugin implements EventSubscriberInterface
{
    public function getName(): string
    {
        return 'OpenAI Image';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [OpenAIImageGenerationTool::class];
    }

    public function skillPaths(): array
    {
        return [__DIR__ . '/../skills'];
    }

    public function agentTemplatePaths(): array
    {
        return [__DIR__ . '/../agent-templates'];
    }

    /**
     * Translation catalogues this plugin ships, declared through
     * {@see \Spora\Plugins\PluginInterface::translationsPaths()}.
     *
     * Keys are the English source text — `de.json` reads
     * `{"Image model identifier.": "Bezeichner des Bildmodells."}`, not
     * `{"model": "…"}`. That is what makes a copy edit detectable: the key
     * changes and the old entry orphans. The gate is this plugin's own
     * `LocaleCatalogTest`, not the host's `i18n:check` — that command
     * harvests core's `app/` and reads core's `locales/en.json`, so run
     * against a plugin repo it reports every catalogue key as an orphan.
     *
     * **Only operator copy belongs in here.** A string the model reads is
     * never translated: not `#[Tool].description`, not either
     * `#[ToolOperation].description`, not any `#[ToolParameter].description`,
     * and not `ToolResult` content. `#[ToolSetting].label` is excluded for
     * the usual reason, and the `size` / `quality` / `background`
     * `#[ToolParameter]` enums are wire values. `locales/de.json` holds
     * the tool's `#[Tool].displayName`, the four
     * `#[ToolSetting].description` values and the manifest description.
     *
     * Requires `spora-core >= 0.31.0` for the catalogue to be loaded. On an
     * older core the host never calls this hook and the plugin renders in
     * English.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
        ];
    }

    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions($this->containerDefinitions());
    }

    /**
     * Bindings registered with PHP-DI's {@see \DI\ContainerBuilder} by
     * {@see onContainerBuilding()}. Extracted as a public method so the
     * plugin tests can assert against the registered contract without
     * reflecting on the builder's private state.
     *
     * @return array<string, mixed>
     */
    public function containerDefinitions(): array
    {
        return [
            OpenAIImageHttpClient::class => \DI\autowire(),
            OpenAIImageMediaArchiveResolver::class => static function (
                MediaAssetReader $reader,
                ?LoggerInterface $logger,
            ): OpenAIImageMediaArchiveResolver {
                return new OpenAIImageMediaArchiveResolver(
                    static fn(string $id, ?int $userId): ?array => $reader->readAsset($id, $userId),
                    $logger,
                );
            },
            OpenAIImageGenerationTool::class => \DI\autowire()
                ->method('setMediaArchive', \DI\get(MediaArchiveService::class))
                ->method('setMediaArchiveResolver', \DI\get(OpenAIImageMediaArchiveResolver::class))
                ->method('setLogger', \DI\get(LoggerInterface::class)),
        ];
    }
}
