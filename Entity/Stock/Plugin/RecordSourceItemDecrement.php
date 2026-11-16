<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock\Plugin;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Entity\Stock\SourceItemRecorder;
use Magento\Inventory\Model\SourceItem\Command\DecrementSourceItemQty;

/**
 * Orders, shipments and refunds change the quantity with a direct UPDATE, not a save.
 */
class RecordSourceItemDecrement
{
    public function __construct(
        private readonly SourceItemRecorder $recorder
    ) {
    }

    /**
     * Record the source items the command wrote.
     *
     * @param DecrementSourceItemQty $subject
     * @param null $result
     * @param array<int, mixed> $items
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(DecrementSourceItemQty $subject, $result, array $items): void
    {
        $this->recorder->record(EventVerb::Updated, array_column($items, 'source_item'));
    }
}
