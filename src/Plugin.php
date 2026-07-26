<?php

declare(strict_types=1);

namespace Spora\Plugins\Skeleton;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Skeleton\Tools\EchoTool;

/**
 * Plugin entry point — extending {@see AbstractPlugin} (rather than directly
 * implementing {@see \Spora\Plugins\PluginInterface}) means we only have to
 * override the two hooks we actually use: getName() and tools().
 *
 * The base class provides no-op defaults for autoload(), drivers(),
 * recipePaths(), skillPaths(), agentTemplatePaths(), schemaVersion(),
 * migrationsPath(), and register().
 */
final class SkeletonPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Skeleton';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            EchoTool::class,
        ];
    }

    // --- Optional hooks: delete any of the three methods below if your
    //     plugin ships no skills / templates / recipes. Use
    //     `$this->pluginDir()` (provided by AbstractPlugin) to resolve
    //     paths under the plugin root — no ReflectionClass needed.

    /**
     * Absolute path to the directory holding this plugin's skills.
     * SkillScanner walks it depth-1; immediate children must each
     * contain a SKILL.md. Remove this method if your plugin ships
     * no skills.
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            $this->pluginDir() . '/skills',
        ];
    }

    /**
     * Absolute paths to agent-template files (.json / .yaml / .yml) this
     * plugin ships. Scanner reads depth-0 from each path. Remove this
     * method if your plugin ships no agent templates.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            $this->pluginDir() . '/agent-templates',
        ];
    }
}
