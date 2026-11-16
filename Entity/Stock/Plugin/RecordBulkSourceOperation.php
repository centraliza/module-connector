<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock\Plugin;

use Centraliza\Connector\Entity\Stock\SourceItemRecorder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bulk actions of the product grid (assign sources, unassign sources,
 * transfer inventory) write source items with direct SQL, past every other
 * hook. What they did is read from the table: the source items of the SKUs
 * involved, before and after.
 */
class RecordBulkSourceOperation
{
    public function __construct(
        private readonly SourceItemRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Report the source items the bulk operation created, changed or removed.
     *
     * @param object $subject
     * @param callable $proceed
     * @param mixed ...$arguments
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(object $subject, callable $proceed, ...$arguments)
    {
        $skus = [];
        $before = null;
        try {
            $skus = $this->skus($arguments);
            $before = $this->recorder->storedForSkus($skus);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not read stock before a bulk operation.', ['exception' => $e]);
        }

        $result = $proceed(...$arguments);

        if ($before !== null) {
            try {
                $this->recorder->recordDifference($before, $this->recorder->storedForSkus($skus));
            } catch (Throwable $e) {
                $this->logger->error('Centraliza Connector could not record a bulk stock operation.', ['exception' => $e]);
            }
        }

        return $result;
    }

    /**
     * The SKUs are the first list among the arguments: plain strings, or transfer items.
     *
     * @param array<int|string, mixed> $arguments
     * @return string[]
     */
    private function skus(array $arguments): array
    {
        foreach ($arguments as $argument) {
            if (!is_array($argument)) {
                continue;
            }
            $skus = [];
            foreach ($argument as $item) {
                if (is_object($item) && method_exists($item, 'getSku')) {
                    $skus[] = (string)$item->getSku();
                } elseif (is_scalar($item)) {
                    $skus[] = (string)$item;
                }
            }

            return array_values(array_unique($skus));
        }

        return [];
    }
}
