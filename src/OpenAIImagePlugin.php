<?php

declare(strict_types=1);

namespace Spora\Plugins\OpenAIImage;

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\OpenAIImage\Support\OpenAIImageHttpClient;
use Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;

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
            OpenAIImageGenerationTool::class => \DI\autowire()
                ->method('setMediaArchive', \DI\get(MediaArchiveService::class))
                ->method('setLogger', \DI\get(LoggerInterface::class)),
        ]);
    }
}
