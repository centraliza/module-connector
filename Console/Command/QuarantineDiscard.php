<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes quarantined events for good; Centraliza is never told about them.
 */
class QuarantineDiscard extends QuarantineSelection
{
    private int $discarded = 0;

    protected function configure(): void
    {
        $this->setName('centraliza:quarantine:discard')
            ->setDescription('Deletes quarantined Centraliza Connector events without sending them');
        parent::configure();
    }

    protected function handle(array $rows, OutputInterface $output): void
    {
        $this->discarded += $this->quarantine->remove(
            array_map(static fn (array $row): int => (int)$row['quarantine_id'], $rows)
        );
    }

    protected function finish(OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>%d event(s) discarded.</info>', $this->discarded));

        return Command::SUCCESS;
    }
}
