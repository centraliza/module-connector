<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock\Plugin;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Entity\Stock\SourceItemRecorder;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin, REST and the legacy stock item all end up in this command. Magento
 * calls it on every product save, so only quantity or status changes count.
 */
class RecordSourceItemsSave
{
    public function __construct(
        private readonly SourceItemRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Compare what was stored before the write with what was written.
     *
     * @param SourceItemsSaveInterface $subject
     * @param callable $proceed
     * @param array<int, mixed> $sourceItems
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(SourceItemsSaveInterface $subject, callable $proceed, array $sourceItems): void
    {
        $before = null;
        try {
            $before = $this->recorder->stored($sourceItems);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not read stock before a save.', ['exception' => $e]);
        }

        $proceed($sourceItems);

        try {
            $this->recorder->record(EventVerb::Updated, $sourceItems, $before);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a stock save.', ['exception' => $e]);
        }
    }
}
