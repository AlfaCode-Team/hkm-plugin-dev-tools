<?php

declare(strict_types=1);

namespace Plugins\DevTools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\DevTools\Commands\ConfigClearCommand;
use Plugins\DevTools\Commands\ConfigShowCommand;
use Plugins\DevTools\Commands\MakeControllerCommand;
use Plugins\DevTools\Commands\MakeEntityCommand;
use Plugins\DevTools\Commands\MakeJobCommand;
use Plugins\DevTools\Commands\MakeListenerCommand;
use Plugins\DevTools\Commands\MakePluginCommand;
use Plugins\DevTools\Commands\MakeRepositoryCommand;
use Plugins\DevTools\Commands\MakeServiceCommand;
use Plugins\DevTools\Commands\MakeValueObjectCommand;
use Plugins\DevTools\Commands\ModuleListCommand;
use Plugins\DevTools\Commands\ModuleInfoCommand;
use Plugins\DevTools\Commands\ProjectListCommand;
use Plugins\DevTools\Commands\RoutesListCommand;

/**
 * DevTools plugin — GDA scaffolding generators, one per layer.
 *
 * Pure developer tooling: no domain, no routes. Registers CLI commands that
 * emit GDA-compliant skeletons so new plugins follow the architecture by
 * default.
 *
 * WHY THE GENERATORS MATTER MORE HERE THAN IN A LOOSER FRAMEWORK
 * -------------------------------------------------------------
 * This architecture asks a developer to hold a lot in their head before they
 * can write anything: five access rules, module.json, scoped containers, two
 * event kinds, the transaction shape, exception translation per layer. Almost
 * all of it is MECHANICAL — which means a generator can encode it, and the
 * strictest thing about the framework becomes the easiest thing to get right.
 * That is the point of these stubs being opinionated rather than empty.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'dev.tooling';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Scaffolding, one per GDA layer. Each stub encodes that layer's rules —
        // the architecture is strict but almost entirely mechanical, so a
        // generator can obey it on the author's behalf.
        $cli->command(MakePluginCommand::class);
        $cli->command(MakeControllerCommand::class);   // Infrastructure/Http
        $cli->command(MakeServiceCommand::class);      // Application
        $cli->command(MakeRepositoryCommand::class);   // Infrastructure/Persistence
        $cli->command(MakeEntityCommand::class);       // Domain/Entities
        $cli->command(MakeValueObjectCommand::class);  // Domain/ValueObjects
        $cli->command(MakeJobCommand::class);          // Infrastructure/Jobs
        $cli->command(MakeListenerCommand::class);     // Infrastructure/Listeners
        $cli->command(ModuleListCommand::class);
        $cli->command(ModuleInfoCommand::class);
        $cli->command(RoutesListCommand::class);
        $cli->command(ProjectListCommand::class);
        $cli->command(ConfigShowCommand::class);
        $cli->command(ConfigClearCommand::class);
    }
}
