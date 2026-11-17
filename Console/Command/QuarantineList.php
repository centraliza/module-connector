<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Centraliza\Connector\Infra\Persistence\QuarantineStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the events delivery gave up on, oldest first.
 */
class QuarantineList extends Command
{
    private const OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly QuarantineStore $quarantine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('centraliza:quarantine:list')
            ->setDescription('Lists the events Centraliza Connector stopped trying to deliver')
            ->addOption(self::OPTION_LIMIT, 'l', InputOption::VALUE_REQUIRED, 'How many to show', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = $this->quarantine->count();
        if ($total === 0) {
            $output->writeln('<info>Quarantine is empty.</info>');

            return Command::SUCCESS;
        }

        $rows = $this->quarantine->rows(max(1, (int)$input->getOption(self::OPTION_LIMIT)));
        $table = new Table($output);
        $table->setHeaders(['ID', 'Stream', 'Verb', 'Key', 'Attempts', 'Reason', 'Quarantined at']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['quarantine_id'],
                $row['stream'],
                $row['verb'],
                $row['key_json'],
                $row['attempts'],
                $row['reason'],
                $row['quarantined_at'],
            ]);
        }
        $table->render();
        $output->writeln(sprintf('Showing %d of %d.', count($rows), $total));

        return Command::SUCCESS;
    }
}
