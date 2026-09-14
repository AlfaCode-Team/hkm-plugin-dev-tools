<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate a Domain value object inside an existing plugin.
 *
 * Usage: make:value-object Invoice Money
 *   -> plugins/Invoice/Domain/ValueObjects/Money.php
 *
 * The stub is `final readonly` with a private constructor that validates, and
 * operations that return NEW instances. That shape is what makes a value object
 * worth having: once constructed it is valid, and it stays valid, because nothing
 * can mutate it into an invalid state afterwards.
 *
 * `--money` emits the integer-cents variant, because "never use float for money"
 * is a rule this framework states and one a generator can simply obey.
 */
final class MakeValueObjectCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:value-object';
        $this->description = 'Generate a Domain value object (final readonly, validating private ctor)';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Value object name');
        $this->addOption('money', 'm', 'Emit a money value object backed by integer cents');
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
            $name = (new TextInput('Value object name'))->placeholder('e.g. EmailAddress')->run();
        }

        $studly = $this->studly($name);
        $ns     = "Plugins\\{$plugin}\\Domain\\ValueObjects";
        $path   = $this->pluginsRoot() . "/{$plugin}/Domain/ValueObjects/{$studly}.php";

        $stub = $this->hasOption('money')
            ? $this->moneyStub($ns, $studly)
            : $this->plainStub($ns, $studly);

        return $this->writeFile($path, $stub, (bool) $this->hasOption('force'))
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function plainStub(string $ns, string $studly): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        // Zero imports from outside Domain/ — see the entity stub for why.

        /**
         * {$studly}.
         *
         * `final readonly` with a PRIVATE constructor: the only way to hold one of
         * these is to have gone through {@see of()}, which validates. So a
         * {$studly} that exists is a {$studly} that is valid — callers never have to
         * re-check, and there is no setter to make it invalid later.
         */
        final readonly class {$studly}
        {
            private function __construct(private string \$value)
            {
                if (\$this->value === '') {
                    throw new \\DomainException('{$studly} cannot be empty.');
                }
            }

            public static function of(string \$value): self
            {
                return new self(trim(\$value));
            }

            public function value(): string
            {
                return \$this->value;
            }

            /**
             * Value objects compare by VALUE, never by identity — two {$studly}s
             * holding the same value are the same {$studly}.
             */
            public function equals(self \$other): bool
            {
                return \$this->value === \$other->value;
            }

            public function __toString(): string
            {
                return \$this->value;
            }
        }

        PHP;
    }

    private function moneyStub(string $ns, string $studly): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        /**
         * {$studly} — an amount of money.
         *
         * STORED AS INTEGER CENTS, ALWAYS.
         *
         * A float cannot represent 0.1 exactly, so summing a column of prices in
         * floats drifts. It drifts slowly, which is worse than drifting fast: the
         * error shows up as a reconciliation that is off by pennies months later,
         * with no single transaction to blame. Integers do not have that failure.
         *
         * Every operation returns a NEW instance, and mixing currencies throws
         * rather than silently adding them.
         */
        final readonly class {$studly}
        {
            private function __construct(
                private int \$amount,      // CENTS
                private string \$currency, // ISO 4217
            ) {
                if (\$this->amount < 0) {
                    throw new \\DomainException('{$studly} cannot be negative.');
                }

                if (strlen(\$this->currency) !== 3) {
                    throw new \\DomainException('Currency must be a 3-letter ISO 4217 code.');
                }
            }

            /** From a major-unit amount: of(10.50, 'USD') === 1050 cents. */
            public static function of(int|float \$amount, string \$currency): self
            {
                return new self((int) round(\$amount * 100), strtoupper(\$currency));
            }

            /** From cents — use this when reading a value straight out of storage. */
            public static function fromCents(int \$cents, string \$currency): self
            {
                return new self(\$cents, strtoupper(\$currency));
            }

            public function add(self \$other): self
            {
                \$this->assertSameCurrency(\$other);

                return new self(\$this->amount + \$other->amount, \$this->currency);
            }

            public function subtract(self \$other): self
            {
                \$this->assertSameCurrency(\$other);

                return new self(\$this->amount - \$other->amount, \$this->currency);
            }

            public function amount(): int      { return \$this->amount; }
            public function currency(): string { return \$this->currency; }

            /** The major-unit value — for DISPLAY only, never for arithmetic. */
            public function value(): float
            {
                return \$this->amount / 100;
            }

            public function equals(self \$other): bool
            {
                return \$this->amount === \$other->amount && \$this->currency === \$other->currency;
            }

            private function assertSameCurrency(self \$other): void
            {
                if (\$this->currency !== \$other->currency) {
                    throw new \\DomainException(
                        "Cannot combine {\$this->currency} with {\$other->currency}."
                    );
                }
            }
        }

        PHP;
    }
}
