<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate a queue job inside an existing plugin.
 *
 * Usage: make:job Invoice SendInvoiceEmail --queue=emails
 *   -> plugins/Invoice/Infrastructure/Jobs/SendInvoiceEmailJob.php
 *
 * The stub implements BOTH halves of JobContract. failed() is the half that gets
 * left out, and leaving it out means a job that exhausts its retries disappears
 * silently — no notification, no compensating action, no record beyond a queue
 * counter nobody is watching.
 *
 * It also prints the module.json jobs[] entry, including retry and timeout,
 * because those are honoured per job only when they are DECLARED — a job that
 * declares neither inherits the loop-wide defaults.
 */
final class MakeJobCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:job';
        $this->description = 'Generate a queue job (handle + failed) inside a plugin';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Job name without the "Job" suffix');
        $this->addOption('queue', 'q', 'Queue to dispatch on (default: default)', acceptsValue: true);
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
            $name = (new TextInput('Job name'))->placeholder('e.g. SendInvoiceEmail')->run();
        }

        $studly = $this->studly($name);
        $queue  = (string) ($this->option('queue') ?? '') ?: 'default';
        $ns     = "Plugins\\{$plugin}\\Infrastructure\\Jobs";
        $path   = $this->pluginsRoot() . "/{$plugin}/Infrastructure/Jobs/{$studly}Job.php";

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Pipelines\\Worker\\Contracts\\JobContract;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Pipelines\\Worker\\{JobPayload, JobResult};
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Ports\\LoggerPort;

        /**
         * {$studly}.
         *
         * Runs in a WorkerLoop container with the project's essential modules
         * registered — so a port or published contract this job needs is injected
         * the same way it would be in a request.
         */
        final class {$studly}Job implements JobContract
        {
            public function __construct(
                private readonly LoggerPort \$logger,
            ) {}

            /**
             * Do the work.
             *
             * THROW to retry. The worker applies this job's declared retry strategy
             * and backoff; swallowing the exception and returning success() would
             * mark a failed job complete and lose it.
             *
             * Return skipped() when there is legitimately nothing to do — an
             * already-processed record, a cancelled order. That is a SUCCESS, not a
             * failure, and must not consume a retry.
             */
            public function handle(JobPayload \$payload): JobResult
            {
                \$data = \$payload->data();

                // if (\$alreadyDone) {
                //     return JobResult::skipped('Nothing to do — already processed.');
                // }

                return JobResult::success(['processed' => 1]);
            }

            /**
             * Called once, after the LAST retry has failed.
             *
             * This is where the compensating action goes — refund the charge, mark
             * the record failed, notify someone. A job without a real failed() is a
             * job whose failures are invisible.
             */
            public function failed(JobPayload \$payload, \\Throwable \$e): void
            {
                \$this->logger->error('{$studly}Job failed after {attempts} attempts: {message}', [
                    'attempts'  => \$payload->attempts(),
                    'message'   => \$e->getMessage(),
                    'jobId'     => \$payload->jobId(),
                    'exception' => \$e,
                ]);
            }
        }

        PHP;

        if (!$this->writeFile($path, $stub, (bool) $this->hasOption('force'))) {
            return self::FAILURE;
        }

        $handler = "Plugins\\\\{$plugin}\\\\Infrastructure\\\\Jobs\\\\{$studly}Job";
        $jobName = $this->snake($plugin) . '.' . $this->kebab($studly);

        $this->info('');
        $this->info('Add to plugins/' . $plugin . '/module.json:');
        $this->info('');
        $this->info('  "jobs": [');
        $this->info(sprintf(
            '    { "name": "%s", "handler": "%s", "queue": "%s",',
            $jobName,
            $handler,
            $queue,
        ));
        $this->info('      "timeout": 30,');
        $this->info('      "retry": { "max": 3, "strategy": "exponential", "base": 5, "jitter": true } }');
        $this->info('  ]');
        $this->info('');
        $this->info('retry and timeout are honoured PER JOB only when declared —');
        $this->info('a job that declares neither inherits the loop-wide defaults.');

        return self::SUCCESS;
    }
}
