<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Centraliza\Connector\Infra\Persistence\QuarantineStore;
use Centraliza\Connector\Infra\Queue\QueuePublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts quarantined events back on the queue with their attempts reset.
 */
class QuarantineRetry extends QuarantineSelection
{
    private int $requeued = 0;

    private int $unreadable = 0;

    public function __construct(
        QuarantineStore $quarantine,
        private readonly QueuePublisher $publisher
    ) {
        parent::__construct($quarantine);
    }

    protected function configure(): void
    {
        $this->setName('centraliza:quarantine:retry')
            ->setDescription('Sends quarantined Centraliza Connector events again, starting from zero attempts');
        parent::configure();
    }

    protected function handle(array $rows, OutputInterface $output): void
    {
        $published = [];
        try {
            foreach ($rows as $row) {
                $event = $this->quarantine->event($row);
                if ($event === null) {
                    $this->unreadable++;
                    $output->writeln(sprintf('<comment>#%d cannot be read as an event and stays in quarantine.</comment>', $row['quarantine_id']));
                    continue;
                }
                $this->publisher->publish((string)$row['lane'], $event);
                $published[] = (int)$row['quarantine_id'];
            }
        } finally {
            // Whatever reached the queue leaves quarantine, even if a later publish failed.
            $this->requeued += $this->quarantine->remove($published);
        }
    }

    protected function finish(OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>%d event(s) put back on the queue.</info>', $this->requeued));

        return $this->unreadable > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
