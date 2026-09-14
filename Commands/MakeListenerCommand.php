<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate an event listener inside an existing plugin.
 *
 * Usage: make:listener Invoice PaymentSucceeded
 *   -> plugins/Invoice/Infrastructure/Listeners/PaymentSucceededListener.php
 *
 * A listener is how one module reacts to another WITHOUT importing it. The stub
 * therefore reads primitives out of the payload rather than type-hinting the
 * emitting module's event class: an integration event carries primitives on
 * purpose, because the subscriber may not have the publisher's value objects —
 * and depending on them would rebuild the coupling the event bus removed.
 */
final class MakeListenerCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:listener';
        $this->description = 'Generate an integration-event listener inside a plugin';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Listener name without the "Listener" suffix');
        $this->addOption('event', 'e', 'Event name to subscribe to (e.g. payment.succeeded)', acceptsValue: true);
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
            $name = (new TextInput('Listener name'))->placeholder('e.g. PaymentSucceeded')->run();
        }

        $studly = $this->studly($name);
        $event  = (string) ($this->option('event') ?? '') ?: $this->dotted($studly);
        $ns     = "Plugins\\{$plugin}\\Infrastructure\\Listeners";
        $path   = $this->pluginsRoot() . "/{$plugin}/Infrastructure/Listeners/{$studly}Listener.php";

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Events\\Contracts\\{EventListenerContract, IntegrationEventContract};
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Ports\\LoggerPort;

        /**
         * Reacts to `{$event}`.
         *
         * Note what is NOT imported: the publishing module's concrete event class.
         * The parameter is typed as the kernel's IntegrationEventContract and the
         * data is read out of payload() as primitives — which is exactly why
         * integration events carry primitives. Type-hinting the publisher's class
         * here would rebuild the coupling the event bus exists to remove, and would
         * fail outright if that plugin is not installed.
         */
        final class {$studly}Listener implements EventListenerContract
        {
            public function __construct(
                private readonly LoggerPort \$logger,
            ) {}

            public function handle(IntegrationEventContract \$event): void
            {
                /** @var array<string, mixed> \$payload */
                \$payload = \$event->payload();

                // A listener failure is ISOLATED by the EventBus — it will not take
                // down the dispatching request, and other listeners still run. That
                // is a good default and a trap: work that MUST happen has to be
                // durable, which means queueing a job rather than doing it here.
                \$this->logger->info('{$studly}Listener handled {event}', [
                    'event'   => \$event->name(),
                    'version' => \$event->version(),
                    'payload' => \$payload,
                ]);
            }
        }

        PHP;

        if (!$this->writeFile($path, $stub, (bool) $this->hasOption('force'))) {
            return self::FAILURE;
        }

        $this->info('');
        $this->info('Subscribe it in ' . $plugin . '\\Provider::boot():');
        $this->info('');
        $this->info(sprintf("    \$events->subscribe('%s', %sListener::class);", $event, $studly));
        $this->info('');
        $this->info('And declare the event in the PUBLISHING plugin\'s module.json "emits": [].');

        return self::SUCCESS;
    }

    /** 'PaymentSucceeded' → 'payment.succeeded' */
    private function dotted(string $studly): string
    {
        return str_replace('_', '.', $this->snake($studly));
    }
}
