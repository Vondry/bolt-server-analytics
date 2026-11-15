<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TomVondracek\RequestAnalytics\Aggregation\RollupResult;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;

/**
 * The cron backstop of the rollup, for busy sites and for sites whose
 * administrators rarely log in: `0 * * * * bin/console request-analytics:rollup`.
 */
#[AsCommand(name: 'request-analytics:rollup', description: 'Aggregate the raw Request Analytics rows into the report tables.')]
final class RollupCommand extends Command
{
    private const DEFAULT_BUDGET_SECONDS = 50;

    public function __construct(
        private readonly RollupService $rollupService,
        private readonly AnalyticsDatabase $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Seconds after which the command stops; the rest waits for the next run', (string) self::DEFAULT_BUDGET_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $budget = $input->getOption('budget');
        if (! is_numeric($budget) || (int) $budget < 1) {
            $io->error('The budget must be a whole number of seconds, 1 or more.');

            return Command::INVALID;
        }

        $result = $this->database->withSchema(fn (): RollupResult => $this->rollupService->rollup((int) $budget * 1000));

        if ($result->busy) {
            $io->note('Another rollup is running; nothing was done.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d rows aggregated in %d ms, %d pending.', $result->processed, $result->durationMs, $result->pending));

        return Command::SUCCESS;
    }
}
