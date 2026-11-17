<?php

declare(strict_types=1);

namespace Centraliza\Connector\Block\Adminhtml;

use Centraliza\Connector\Backfill\BackfillService;
use Centraliza\Connector\Backfill\BackfillStore;
use Centraliza\Connector\Core\Service\DeliverEvent;
use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Infra\Persistence\CircuitStore;
use Centraliza\Connector\Infra\Persistence\DeliveryLog;
use Centraliza\Connector\Infra\Persistence\QuarantineStore;
use Centraliza\Connector\Infra\Persistence\RetryStore;
use Centraliza\Connector\Infra\Queue\QueueDepth;
use Centraliza\Connector\Messaging\Api\ConfigInterface as MessagingConfig;
use Centraliza\Connector\Messaging\Model\Api\QuotaReservation;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use Throwable;

/**
 * The Centraliza Connector dashboard: how the connection stands, the buttons that send every record of a
 * stream, and how those sends are going.
 */
class Dashboard extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Settings $settings,
        private readonly MessagingConfig $messagingConfig,
        private readonly QuotaReservation $quota,
        private readonly BackfillService $backfills,
        private readonly BackfillStore $backfillStore,
        private readonly CircuitStore $circuits,
        private readonly QueueDepth $queueDepth,
        private readonly RetryStore $retryStore,
        private readonly QuarantineStore $quarantine,
        private readonly ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getWebsiteName(): ?string
    {
        $websiteId = $this->settings->reportingWebsiteId();
        if ($websiteId === null) {
            return null;
        }

        return (string)$this->_storeManager->getWebsite($websiteId)->getName();
    }

    public function getEnvironment(): string
    {
        return $this->settings->environment();
    }

    public function isLive(): bool
    {
        return $this->settings->isLive();
    }

    public function getChannelId(): string
    {
        return $this->settings->connectionId();
    }

    /**
     * The workspace of the checked key, or null when the key of the reporting website was not checked.
     */
    public function getCheckedWorkspace(): ?string
    {
        $websiteId = $this->settings->reportingWebsiteId();
        if ($websiteId === null || !$this->messagingConfig->isConnected($websiteId)) {
            return null;
        }

        return (string)$this->messagingConfig->getWorkspaceName($websiteId);
    }

    public function getRequestLimit(): ?int
    {
        return $this->quota->getLimit();
    }

    /**
     * @return array{status: string, open_until: int}
     */
    public function getCircuit(): array
    {
        $state = $this->circuits->load(DeliverEvent::CIRCUIT_SCOPE);

        return ['status' => $state->status->value, 'open_until' => $state->openUntil];
    }

    /**
     * @return array<string, int>|null waiting by lane; null with RabbitMQ, whose depth the broker shows
     */
    public function getQueue(): ?array
    {
        return $this->queueDepth->byLane();
    }

    public function getHeld(): int
    {
        return $this->retryStore->summary()['count'];
    }

    public function getQuarantined(): int
    {
        return $this->quarantine->count();
    }

    /**
     * @return array<string, int> requests of the last 24 hours, by outcome
     */
    public function getLastDay(): array
    {
        $connection = $this->resource->getConnection();

        return array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(DeliveryLog::TABLE), ['outcome', 'total' => 'COUNT(*)'])
                ->where('created_at >= NOW() - INTERVAL 1 DAY')
                ->group('outcome')
        ));
    }

    /**
     * @return list<array{code: string, label: Phrase, count: ?int, active: ?array<string, mixed>}>
     */
    public function getStreams(): array
    {
        $active = [];
        foreach ($this->backfillStore->active() as $backfill) {
            $active[(string)$backfill['stream']] = $backfill;
        }

        $streams = [];
        foreach ($this->backfills->streams() as $code) {
            try {
                $count = $this->backfills->count($code);
            } catch (Throwable) {
                $count = null;
            }
            $streams[] = [
                'code' => $code,
                'label' => $this->streamLabel($code),
                'count' => $count,
                'active' => $active[$code] ?? null,
            ];
        }

        return $streams;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentBackfills(): array
    {
        return $this->backfillStore->recent(10);
    }

    public function streamLabel(string $code): Phrase
    {
        return match ($code) {
            'products' => __('Products'),
            'prices' => __('Prices'),
            'stock' => __('Stock'),
            'locations' => __('Sources'),
            'customers' => __('Customers'),
            'customer_groups' => __('Customer groups'),
            'orders' => __('Orders'),
            'carts' => __('Carts'),
            default => __($code),
        };
    }

    public function statusLabel(string $status): Phrase
    {
        return match ($status) {
            BackfillStore::PENDING => __('Waiting'),
            BackfillStore::RUNNING => __('Putting on the queue'),
            BackfillStore::DONE => __('All on the queue'),
            BackfillStore::CANCELLED => __('Cancelled'),
            BackfillStore::FAILED => __('Failed'),
            default => __($status),
        };
    }

    public function getStartUrl(): string
    {
        return $this->getUrl('centraliza_connector/backfill/start');
    }

    public function getCancelUrl(): string
    {
        return $this->getUrl('centraliza_connector/backfill/cancel');
    }

    public function getRetryQuarantineUrl(): string
    {
        return $this->getUrl('centraliza_connector/quarantine/retry');
    }

    public function getDeliveriesUrl(): string
    {
        return $this->getUrl('centraliza_connector/delivery/index');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'centraliza_connector']);
    }

    public function canSend(): bool
    {
        return $this->settings->reportingWebsiteId() !== null;
    }

    public function displayTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return $this->formatDate($value, \IntlDateFormatter::SHORT, true);
    }
}
