<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Location\Plugin;

use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Service\SnapshotDiff;
use Centraliza\Connector\Entity\Location\LocationSnapshot;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sources are global: they are reported whenever any store view reports.
 * A source cannot be deleted in Magento, only disabled, which is an update.
 */
class RecordLocationChange
{
    public function __construct(
        private readonly LocationSnapshot $snapshot,
        private readonly SnapshotDiff $diff,
        private readonly ChangeRecorderInterface $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * A source was created or edited.
     *
     * @param SourceRepositoryInterface $subject
     * @param callable $proceed
     * @param SourceInterface $source
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSave(SourceRepositoryInterface $subject, callable $proceed, SourceInterface $source): void
    {
        $this->around(static fn () => $proceed($source), [(string)$source->getSourceCode()]);
    }

    /**
     * Sources were assigned to a stock, unassigned from it, or had their priority changed.
     *
     * @param object $subject
     * @param callable $proceed
     * @param array<int, mixed> $links
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(object $subject, callable $proceed, array $links): void
    {
        $sourceCodes = [];
        foreach ($links as $link) {
            if (is_object($link) && method_exists($link, 'getSourceCode')) {
                $sourceCodes[] = (string)$link->getSourceCode();
            }
        }
        $this->around(static fn () => $proceed($links), $sourceCodes);
    }

    /**
     * @param string[] $sourceCodes
     */
    private function around(callable $operation, array $sourceCodes): void
    {
        $before = null;
        try {
            $before = $this->snapshot->forSources($sourceCodes);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not read a source before a change.', ['exception' => $e]);
        }

        $operation();

        if ($before === null) {
            return;
        }
        try {
            foreach ($this->diff->verbs($before, $this->snapshot->forSources($sourceCodes)) as $sourceCode => $verb) {
                $this->recorder->record('locations', $verb, ['source_code' => (string)$sourceCode]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a source change.', ['exception' => $e]);
        }
    }
}
