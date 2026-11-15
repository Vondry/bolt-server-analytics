<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;

/**
 * Creates or updates the extension's tables right away, e.g. in a deploy
 * script. Not required: they are also created on first use.
 *
 * Also makes the extension's files owner and group only (restored from a
 * backup, copied by hand, created with another umask). With `--vacuum` it also
 * rewrites its own SQLite file without the free pages that may still hold
 * deleted rows: not by default, since it locks the file while it runs and
 * needs as much free disk as the file takes, which a deploy script should not
 * do unasked.
 */
#[AsCommand(name: 'request-analytics:install', description: 'Create or update the Request Analytics tables.')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly AnalyticsDatabase $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('vacuum', null, InputOption::VALUE_NONE, 'Also rewrite the SQLite file without the space of deleted rows (locks it meanwhile, needs free disk of its size)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $where = $this->database->target()->describe();

        $statements = $this->database->install();
        $restricted = $this->database->restrictFiles();
        $vacuumed = $input->getOption('vacuum') === true && $this->database->vacuum();

        if ($output->isVerbose()) {
            $io->listing([...$statements, ...$restricted]);
        }

        if ($restricted !== []) {
            $io->writeln(sprintf('%d file(s) and director(ies) of Request Analytics are now for their owner and group only.', count($restricted)));
        }

        if ($vacuumed) {
            $io->writeln('The SQLite file of Request Analytics was rewritten without the space of deleted rows.');
        }

        if ($statements === []) {
            $io->success(sprintf('The Request Analytics tables in %s are up to date.', $where));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d statement(s) run on the Request Analytics tables in %s.', count($statements), $where));

        return Command::SUCCESS;
    }
}
