<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate an Infrastructure repository inside an existing plugin.
 *
 * Usage: make:repository Invoice Invoice
 *   -> plugins/Invoice/Infrastructure/Persistence/InvoiceRepository.php
 *
 * WHY THE STUB IS THIS OPINIONATED
 * --------------------------------
 * The repository layer has the most rules attached to it in this framework, and
 * every one of them is mechanical:
 *
 *   - DatabasePort is the ONLY external dependency (no HTTP, no vendor SDK)
 *   - every query is scoped to Identity::$tenantId
 *   - \PDOException never escapes — it is translated to RepositoryException
 *   - upsert() rather than a hand-written ON DUPLICATE KEY / ON CONFLICT
 *
 * A generator can encode all four. That turns the framework's strictest layer
 * into the one it is hardest to get wrong — which is the whole argument for
 * scaffolding here rather than an empty class.
 */
final class MakeRepositoryCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:repository';
        $this->description = 'Generate an Infrastructure repository (tenant-scoped, port-only) inside a plugin';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Repository name without the "Repository" suffix');
        $this->addOption('table', 't', 'Table name (defaults to the snake_case plural of the name)', acceptsValue: true);
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
            $name = (new TextInput('Repository name'))->placeholder('e.g. Invoice')->run();
        }

        $studly = $this->studly($name);
        $table  = (string) ($this->option('table') ?? '') ?: $this->snake($studly) . 's';
        $ns     = "Plugins\\{$plugin}\\Infrastructure\\Persistence";
        $path   = $this->pluginsRoot() . "/{$plugin}/Infrastructure/Persistence/{$studly}Repository.php";

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Exceptions\\RepositoryException;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Ports\\DatabasePort;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Security\\Identity;

        /**
         * {$studly} persistence.
         *
         * DatabasePort is the ONLY external dependency this class may take — no
         * HTTP client, no vendor SDK, no other module's service. That is what makes
         * it swappable and what keeps the data-access rules checkable.
         */
        final class {$studly}Repository
        {
            private const TABLE = '{$table}';

            public function __construct(
                private readonly DatabasePort \$db,
                // Tenant scoping is not optional: every statement below filters on
                // this. A query that forgets it returns another tenant's rows.
                private readonly Identity \$identity,
            ) {}

            /** @return array<string, mixed> */
            public function find(string \$id): array
            {
                try {
                    \$row = \$this->db->queryOne(
                        'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                        ['id' => \$id, 'tenant' => \$this->identity->tenantId],
                    );
                } catch (\\PDOException \$e) {
                    // A vendor exception must never escape this layer — the caller
                    // would have to catch \\PDOException to handle it, which couples
                    // every service to the driver.
                    throw new RepositoryException(
                        "Failed to find {$studly} [{\$id}]",
                        layer: 'repository.' . self::TABLE,
                        context: ['id' => \$id],
                        previous: \$e,
                    );
                }

                if (\$row === null) {
                    throw new RepositoryException(
                        "{$studly} [{\$id}] not found",
                        layer: 'repository.' . self::TABLE,
                        context: ['id' => \$id],
                    );
                }

                return \$row;
            }

            /** @return list<array<string, mixed>> */
            public function all(int \$limit = 50, int \$offset = 0): array
            {
                try {
                    return \$this->db->query(
                        'SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = :tenant AND deleted_at IS NULL'
                        . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
                        [
                            'tenant' => \$this->identity->tenantId,
                            'limit'  => \$limit,
                            'offset' => \$offset,
                        ],
                    );
                } catch (\\PDOException \$e) {
                    throw new RepositoryException(
                        'Failed to list {$studly}',
                        layer: 'repository.' . self::TABLE,
                        previous: \$e,
                    );
                }
            }

            /**
             * Atomic insert-or-update.
             *
             * Call the PORT rather than writing ON DUPLICATE KEY / ON CONFLICT by
             * hand — the port compiles the right clause per driver, and a
             * hand-written one silently makes this repository single-driver.
             *
             * @param array<string, mixed> \$values
             */
            public function save(array \$values): void
            {
                \$values['tenant_id'] = \$this->identity->tenantId;

                try {
                    \$this->db->upsert(self::TABLE, \$values, ['id']);
                } catch (\\PDOException \$e) {
                    throw new RepositoryException(
                        'Failed to save {$studly}',
                        layer: 'repository.' . self::TABLE,
                        previous: \$e,
                    );
                }
            }

            public function delete(string \$id): void
            {
                try {
                    \$this->db->execute(
                        'UPDATE ' . self::TABLE . ' SET deleted_at = :now WHERE id = :id AND tenant_id = :tenant',
                        [
                            'now'    => (new \\DateTimeImmutable())->format('Y-m-d H:i:s'),
                            'id'     => \$id,
                            'tenant' => \$this->identity->tenantId,
                        ],
                    );
                } catch (\\PDOException \$e) {
                    throw new RepositoryException(
                        "Failed to delete {$studly} [{\$id}]",
                        layer: 'repository.' . self::TABLE,
                        context: ['id' => \$id],
                        previous: \$e,
                    );
                }
            }
        }

        PHP;

        if (!$this->writeFile($path, $stub, (bool) $this->hasOption('force'))) {
            return self::FAILURE;
        }

        $this->info('');
        $this->info('Bind it as INTERNAL in your Provider::register() — a repository is');
        $this->info('never resolvable from outside its own module:');
        $this->info('');
        $this->info("    \$container->bindInternal({$studly}Repository::class, fn(\$c) => new {$studly}Repository(");
        $this->info('        $c->make(DatabasePort::class),');
        $this->info('        $c->make(Identity::class),');
        $this->info('    ));');

        return self::SUCCESS;
    }
}
