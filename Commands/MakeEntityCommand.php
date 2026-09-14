<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate a Domain entity inside an existing plugin.
 *
 * Usage: make:entity Invoice Invoice
 *   -> plugins/Invoice/Domain/Entities/Invoice.php
 *
 * THE FOUR RULES THE STUB ENCODES
 * -------------------------------
 *   private __construct         an entity is only ever built through a named
 *                               constructor, so there is one place invariants hold
 *   create() records events     the state change and its event are written together
 *   reconstitute() does NOT     hydrating from the database is not a business event;
 *                               emitting one there re-fires history on every read
 *   releaseEvents() clears      returning without clearing dispatches twice
 *
 * Plus the one that is easy to state and easy to forget: ZERO imports outside
 * Domain/. The stub has none, and the docblock says why.
 */
final class MakeEntityCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:entity';
        $this->description = 'Generate a Domain entity (private ctor, named constructors, event buffer)';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Entity name');
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
            $name = (new TextInput('Entity name'))->placeholder('e.g. Invoice')->run();
        }

        $studly = $this->studly($name);
        $ns     = "Plugins\\{$plugin}\\Domain\\Entities";
        $path   = $this->pluginsRoot() . "/{$plugin}/Domain/Entities/{$studly}.php";
        $var    = lcfirst($studly);

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        // NOTHING may be imported here from outside Domain/. Not a port, not a
        // service, not a framework class. An entity that imports infrastructure
        // can only be tested with infrastructure, and the rule that keeps this
        // layer pure is checkable precisely because the import list is empty.

        /**
         * {$studly}.
         *
         * Built only through a named constructor: {@see create()} for something
         * new (which records a domain event) and {@see reconstitute()} for
         * something read back from storage (which must not).
         */
        final class {$studly}
        {
            /** @var list<object> domain events awaiting collection */
            private array \$domainEvents = [];

            /**
             * PRIVATE. Every path into this class goes through a named constructor,
             * so there is exactly one place each invariant is enforced.
             */
            private function __construct(
                private readonly string \$id,
                private string \$status,
            ) {}

            /**
             * A NEW {$studly}. Records the creation event alongside the state change,
             * so the two can never drift apart.
             */
            public static function create(string \$id): self
            {
                \${$var} = new self(id: \$id, status: 'draft');

                // \${$var}->domainEvents[] = new {$studly}CreatedDomainEvent(\$id);

                return \${$var};
            }

            /**
             * Rehydrate from storage. Records NO events: reading a row is not a
             * business event, and emitting one here would re-fire the entity's
             * whole history every time it is loaded.
             */
            public static function reconstitute(string \$id, string \$status): self
            {
                return new self(id: \$id, status: \$status);
            }

            /**
             * A state transition. Check the precondition FIRST — an entity that can
             * be moved into an impossible state is not enforcing anything.
             */
            public function activate(): void
            {
                if (\$this->status !== 'draft') {
                    throw new \\DomainException('Only a draft {$var} can be activated.');
                }

                \$this->status = 'active';

                // \$this->domainEvents[] = new {$studly}ActivatedDomainEvent(\$this->id);
            }

            public function id(): string     { return \$this->id; }
            public function status(): string { return \$this->status; }

            /**
             * Hand over the recorded events AND CLEAR the buffer.
             *
             * The clear is the important half: a releaseEvents() that only returned
             * would dispatch every event again on the next call.
             *
             * @return list<object>
             */
            public function releaseEvents(): array
            {
                \$events = \$this->domainEvents;
                \$this->domainEvents = [];

                return \$events;
            }
        }

        PHP;

        return $this->writeFile($path, $stub, (bool) $this->hasOption('force'))
            ? self::SUCCESS
            : self::FAILURE;
    }
}
