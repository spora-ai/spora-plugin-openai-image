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
 * Binds what php-di cannot infer. The media-archive resolver wraps
 * {@see MediaAssetReader} as a closure so the plugin does not couple to the
 * host's `final` reader.
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
     * The plugin's `locales/` directory — one `<lang>.json` per language.
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
     * The bindings {@see onContainerBuilding()} registers. A public method so
     * the tests can assert the contract without reflecting on the builder.
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
