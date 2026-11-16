<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock\Plugin;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Entity\Stock\SourceItemRecorder;
use Magento\Inventory\Model\ResourceModel\SourceItem\DeleteMultiple;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Source items removed from a product that still exists (a source was
 * unassigned). The stock of a deleted product is reported from the product
 * delete instead: by the time Magento cleans it up here, the product is gone.
 */
class RecordSourceItemsDelete
{
    public function __construct(
        private readonly SourceItemRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record the source items that were removed.
     *
     * @param DeleteMultiple $subject
     * @param mixed $result
     * @param array<int, mixed> $sourceItems
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(DeleteMultiple $subject, $result, array $sourceItems)
    {
        try {
            $this->recorder->record(EventVerb::Deleted, $sourceItems);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a stock removal.', ['exception' => $e]);
        }

        return $result;
    }
}
