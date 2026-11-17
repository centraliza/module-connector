<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Centraliza\Connector\Infra\Persistence\QuarantineStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that acts on quarantined events chosen by id, or on all of them.
 */
abstract class QuarantineSelection extends Command
{
    private const ARGUMENT_IDS = 'ids';
    private const OPTION_ALL = 'all';
    private const PAGE = 500;

    public function __construct(
        protected readonly QuarantineStore $quarantine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(self::ARGUMENT_IDS, InputArgument::IS_ARRAY, 'Quarantine ids, as shown by centraliza:quarantine:list')
            ->addOption(self::OPTION_ALL, null, InputOption::VALUE_NONE, 'Every quarantined event');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$input->getArgument(self::ARGUMENT_IDS)))));
        $all = (bool)$input->getOption(self::OPTION_ALL);
        if (($ids === []) === !$all) {
            $output->writeln('<error>Give the ids to act on, or --all.</error>');

            return Command::INVALID;
        }

        $afterId = 0;
        do {
            $rows = $this->quarantine->rows(self::PAGE, $ids, $afterId);
            if ($rows !== []) {
                $afterId = (int)end($rows)['quarantine_id'];
                $this->handle($rows, $output);
            }
        } while (count($rows) === self::PAGE);

        return $this->finish($output);
    }

    /**
     * @param array<int, array<string, mixed>> $rows one page of the selection
     */
    abstract protected function handle(array $rows, OutputInterface $output): void;

    abstract protected function finish(OutputInterface $output): int;
}
