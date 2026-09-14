<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate an Infrastructure HTTP controller inside an existing plugin.
 *
 * Usage: make:controller Invoice Invoice --resource
 *   -> plugins/Invoice/Infrastructure/Http/Controllers/InvoiceController.php
 *
 * The stub encodes the controller rule this framework states most often and
 * which is broken most often: THREE LINES. DTO in, service call, Response out.
 * It also injects the published CONTRACT rather than the service class, because
 * a controller that type-hints a concrete service has quietly reached past the
 * module boundary the contract exists to draw.
 *
 * It prints the module.json routes[] to paste, because routes are declared there
 * and NOWHERE else — a generator that wrote PHP routes would be teaching the one
 * thing the framework forbids.
 */
final class MakeControllerCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:controller';
        $this->description = 'Generate a thin HTTP controller (contract-injected, 3-line actions)';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Controller name without the "Controller" suffix');
        $this->addOption('resource', 'r', 'Generate index/show/create/update/destroy actions');
        $this->addOption('force', 'f', 'Overwrite if it exists');
    }

    protected function handle(): int
    {
        $plugin = $this->studly((string) ($this->argument('plugin') ?? ''));
        $name   = (string) ($this->argument('name') ?? '');

        if ($plugin === '') {
            $this->error('A target plugin name is required.');

            return self::FAILURE;
        }

        if ($name === '') {
            $name = (new TextInput('Controller name'))->placeholder('e.g. Invoice')->run();
        }

        $studly   = $this->studly($name);
        $resource = (bool) $this->hasOption('resource');
        $ns       = "Plugins\\{$plugin}\\Infrastructure\\Http\\Controllers";
        $contract = "Plugins\\{$plugin}\\API\\Contracts\\{$studly}ServiceContract";
        $path     = $this->pluginsRoot() . "/{$plugin}/Infrastructure/Http/Controllers/{$studly}Controller.php";
        $var      = lcfirst($studly);

        $actions = $resource
            ? $this->resourceActions($studly, $var)
            : $this->singleAction($var);

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Http\\{Request, Response};
        use {$contract};

        /**
         * {$studly} HTTP surface.
         *
         * Controllers translate, they do not decide. Each action is at most three
         * lines: build a DTO, call the service, return a Response. Anything longer
         * is business logic that belongs in the Application layer, where it can be
         * tested without a Request.
         *
         * The dependency is the published CONTRACT, never the concrete service —
         * that is the seam the module boundary is drawn on.
         */
        final class {$studly}Controller
        {
            public function __construct(
                private readonly {$studly}ServiceContract \${$var},
            ) {}

        {$actions}
        }

        PHP;

        if (!$this->writeFile($path, $stub, (bool) $this->hasOption('force'))) {
            return self::FAILURE;
        }

        $this->printRoutes($plugin, $studly, $resource);

        return self::SUCCESS;
    }

    private function singleAction(string $var): string
    {
        return <<<PHP
            public function index(Request \$request): Response
                {
                    return Response::json(\$this->{$var}->all());
                }
        PHP;
    }

    private function resourceActions(string $studly, string $var): string
    {
        return <<<PHP
            public function index(Request \$request): Response
                {
                    return Response::json(\$this->{$var}->all());
                }

                public function show(Request \$request, string \$id): Response
                {
                    \$result = \$this->{$var}->find(\$id);

                    return \$result === null ? Response::notFound() : Response::json(\$result);
                }

                public function create(Request \$request): Response
                {
                    // Validation belongs in the DTO's fromRequest(), not here.
                    \$dto = Create{$studly}DTO::fromRequest(\$request);

                    return Response::json(\$this->{$var}->create(\$dto)->toArray(), 201);
                }

                public function update(Request \$request, string \$id): Response
                {
                    \$dto = Update{$studly}DTO::fromRequest(\$request);

                    return Response::json(\$this->{$var}->update(\$id, \$dto)->toArray());
                }

                public function destroy(Request \$request, string \$id): Response
                {
                    \$this->{$var}->delete(\$id);

                    return Response::empty(204);
                }
        PHP;
    }

    /**
     * Print the module.json entries to paste.
     *
     * Routes live in module.json and nowhere else. Printing them here — rather
     * than writing a PHP route file — is the difference between a generator that
     * teaches the framework and one that undermines it.
     */
    private function printRoutes(string $plugin, string $studly, bool $resource): void
    {
        $handler = "Plugins\\\\{$plugin}\\\\Infrastructure\\\\Http\\\\Controllers\\\\{$studly}Controller";
        $slug    = $this->kebab($studly);
        $name    = $this->snake($studly);

        $routes = $resource
            ? [
                ['GET', '', 'index', "{$name}.index"],
                ['GET', '/{id:num}', 'show', "{$name}.show"],
                ['POST', '', 'create', "{$name}.create"],
                ['PUT', '/{id:num}', 'update', "{$name}.update"],
                ['DELETE', '/{id:num}', 'destroy', "{$name}.destroy"],
            ]
            : [['GET', '', 'index', "{$name}.index"]];

        $this->info('');
        $this->info('Add to plugins/' . $plugin . '/module.json — routes are declared THERE, never in PHP:');
        $this->info('');
        $this->info('  "routePrefix": "/' . $slug . 's",');
        $this->info('  "routes": [');

        foreach ($routes as [$method, $path, $action, $routeName]) {
            $this->info(sprintf(
                '    { "method": "%s", "path": "%s", "handler": "%s@%s", "name": "%s" },',
                $method,
                $path,
                $handler,
                $action,
                $routeName,
            ));
        }

        $this->info('  ]');
        $this->info('');
        $this->info('Set ROUTE_VERIFY_HANDLERS=1 in development — the boot will then verify');
        $this->info('every handler class and method actually exists.');
    }
}
