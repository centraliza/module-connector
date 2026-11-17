<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence;

use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\DeliveryLogInterface;
use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One row per request sent to Centraliza, for the "Sent" screen of the admin. Keeps the key of the record, not
 * the record: the record is read again on every attempt and can carry personal data.
 */
class DeliveryLog implements DeliveryLogInterface
{
    public const TABLE = 'centraliza_connector_delivery_log';

    /** Kept this long, then deleted by the cleanup cron. */
    public const RETENTION_DAYS = 30;

    private const MAX_KEY_LENGTH = 255;
    private const MAX_DETAIL_LENGTH = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(HeldEvent $held, Outcome $outcome, Action $action, int $sendMs): void
    {
        try {
            $event = $held->event;
            $occurredAt = strtotime($event->occurredAt);
            $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
                'website_id' => $this->settings->reportingWebsiteId(),
                'stream' => $event->stream,
                'verb' => $event->verb->value,
                'record_key' => mb_substr(self::keyLabel($event->key), 0, self::MAX_KEY_LENGTH),
                'idempotency_key' => $event->idempotencyKey,
                'lane' => $held->lane,
                'attempt' => $held->attempts + 1,
                'outcome' => $outcome->kind->value,
                'action' => $action->value,
                'http_status' => $outcome->httpStatus,
                'detail' => mb_substr($outcome->detail, 0, self::MAX_DETAIL_LENGTH),
                'mode' => $this->settings->isLive() ? Settings::MODE_LIVE : Settings::MODE_DRY_RUN,
                'environment' => $this->settings->environment(),
                'send_ms' => max(0, $sendMs),
                'lag_seconds' => $occurredAt === false ? null : max(0, $this->clock->now() - $occurredAt),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Centraliza Connector could not log a delivery.', ['exception_class' => $e::class]);
        }
    }

    /**
     * Deletes what is older than the retention.
     *
     * @return int rows deleted
     */
    public function deleteOld(): int
    {
        $connection = $this->resource->getConnection();

        // The database clock, the one that stamped created_at.
        return $connection->delete(
            $this->resource->getTableName(self::TABLE),
            [sprintf('created_at < NOW() - INTERVAL %d DAY', self::RETENTION_DAYS)]
        );
    }

    /**
     * "sku=A1 · source_code=default".
     *
     * @param array<string, scalar> $key
     */
    public static function keyLabel(array $key): string
    {
        $parts = [];
        foreach ($key as $field => $value) {
            $parts[] = $field . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
        }

        return implode(' · ', $parts);
    }
}
