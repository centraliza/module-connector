<?php

declare(strict_types=1);

namespace Centraliza\Connector\Capture;

use Centraliza\Connector\Core\Port\TransactionStateInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * An after-plugin on save() runs before the outer commit when the caller
 * opened the transaction, so the collector is driven from the adapter itself.
 */
class TransactionBoundaryPlugin
{
    public function __construct(
        private readonly Collector $collector,
        private readonly TransactionStateInterface $transaction
    ) {
    }

    /**
     * Publish what was recorded once the outermost transaction is committed.
     *
     * @param AdapterInterface $subject
     * @param AdapterInterface $result
     * @return AdapterInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCommit(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        if ($result->getTransactionLevel() === 0 && !$this->transaction->inTransaction()) {
            $this->collector->flush();
        }

        return $result;
    }

    /**
     * Drop what was recorded: the changes it describes did not happen.
     *
     * @param AdapterInterface $subject
     * @param AdapterInterface $result
     * @return AdapterInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRollBack(AdapterInterface $subject, AdapterInterface $result): AdapterInterface
    {
        $this->collector->discard();

        return $result;
    }
}
