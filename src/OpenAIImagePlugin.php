<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage;

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageMediaArchiveResolver;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;

final class OpenAIImagePlugin extends AbstractPlugin
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

    public function schemaVersion(): int
    {
        return 1;
    }

    public function skillPaths(): array
    {
        return [__DIR__ . '/../skills'];
    }

    public function agentTemplatePaths(): array
    {
        return [__DIR__ . '/../agent-templates'];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([
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
        ]);
    }
}
