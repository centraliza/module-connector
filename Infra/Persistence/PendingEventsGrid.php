<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence;

use Centraliza\Connector\Infra\Queue\QueueDepth;
use Centraliza\Connector\Infra\Queue\QueuePublisher;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Every event not delivered yet, wherever it is: on the queue, waiting for a new attempt, or in quarantine.
 *
 * The three places become one derived table, so the grid filters, sorts and pages over all of them. Messages on the
 * queue are read from the database queue only; with RabbitMQ they are in the broker and this grid does not see them.
 */
class PendingEventsGrid extends SearchResult
{
    public const STATE_QUEUED = 'queued';
    public const STATE_SENDING = 'sending';
    public const STATE_HELD = 'held';
    public const STATE_QUARANTINED = 'quarantined';

    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        private readonly QueueDepth $queueDepth,
        $mainTable = RetryStore::TABLE,
        $resourceModel = null,
        $identifierName = 'pending_id',
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect()
    {
        $connection = $this->getConnection();
        $parts = [$this->heldSelect($connection), $this->quarantinedSelect($connection)];
        if ($this->queueDepth->isDatabaseQueue()) {
            array_unshift($parts, $this->queuedSelect($connection));
        }
        $union = $connection->select()->union($parts, Select::SQL_UNION_ALL);
        $this->getSelect()->from(['main_table' => $union]);
    }

    /**
     * The key is kept as JSON for the text filter; the grid shows it as "field=value · field=value".
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            $key = json_decode((string)$item->getData('record_key'), true);
            if (is_array($key)) {
                $item->setData('record_key', DeliveryLog::keyLabel($key));
            }
        }

        return $this;
    }

    private function queuedSelect(AdapterInterface $connection): Select
    {
        // The database queue stores the message as a JSON string holding the JSON of the event.
        $field = static fn (string $path): \Zend_Db_Expr => new \Zend_Db_Expr(
            "JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(m.body), '$." . $path . "'))"
        );

        return $connection->select()
            ->from(['s' => $this->getTable('queue_message_status')], [])
            ->join(['m' => $this->getTable('queue_message')], 'm.id = s.message_id', [])
            ->join(['q' => $this->getTable('queue')], 'q.id = s.queue_id', [])
            ->columns([
                'pending_id' => new \Zend_Db_Expr("CONCAT('queue-', s.id)"),
                'state' => new \Zend_Db_Expr($connection->quoteInto(
                    'IF(s.status = ?, ' . $connection->quote(self::STATE_SENDING) . ', '
                    . $connection->quote(self::STATE_QUEUED) . ')',
                    QueueDepth::STATUS_IN_PROGRESS
                )),
                'occurred_at' => $this->isoToDatetime($field('at')),
                'stream' => $field('stream'),
                'verb' => $field('verb'),
                'record_key' => new \Zend_Db_Expr("JSON_EXTRACT(JSON_UNQUOTE(m.body), '$.key')"),
                'lane' => new \Zend_Db_Expr(
                    'SUBSTRING(q.name, ' . (strlen(QueuePublisher::TOPIC_PREFIX) + 1) . ')'
                ),
                'attempts' => new \Zend_Db_Expr('CAST(' . $field('attempts') . ' AS UNSIGNED)'),
                'next_attempt_at' => new \Zend_Db_Expr('NULL'),
                'detail' => new \Zend_Db_Expr("''"),
                'idempotency_key' => $field('id'),
            ])
            ->where('q.name LIKE ?', QueuePublisher::TOPIC_PREFIX . '%')
            ->where('s.status IN (?)', [
                QueueDepth::STATUS_NEW,
                QueueDepth::STATUS_IN_PROGRESS,
                QueueDepth::STATUS_RETRY_REQUIRED,
            ]);
    }

    private function heldSelect(AdapterInterface $connection): Select
    {
        return $connection->select()
            ->from(['r' => $this->getTable(RetryStore::TABLE)], [
                'pending_id' => new \Zend_Db_Expr("CONCAT('retry-', r.retry_id)"),
                'state' => new \Zend_Db_Expr($connection->quote(self::STATE_HELD)),
                'occurred_at' => $this->isoToDatetime(new \Zend_Db_Expr('r.occurred_at')),
                'stream' => 'r.stream',
                'verb' => 'r.verb',
                'record_key' => 'r.key_json',
                'lane' => 'r.lane',
                'attempts' => 'r.attempts',
                // Unix seconds made a UTC date without the time zone of the session.
                'next_attempt_at' => new \Zend_Db_Expr("DATE_ADD('1970-01-01', INTERVAL r.next_attempt_at SECOND)"),
                'detail' => 'r.last_error',
                'idempotency_key' => 'r.idempotency_key',
            ]);
    }

    private function quarantinedSelect(AdapterInterface $connection): Select
    {
        return $connection->select()
            ->from(['x' => $this->getTable(QuarantineStore::TABLE)], [
                'pending_id' => new \Zend_Db_Expr("CONCAT('quarantine-', x.quarantine_id)"),
                'state' => new \Zend_Db_Expr($connection->quote(self::STATE_QUARANTINED)),
                'occurred_at' => $this->isoToDatetime(new \Zend_Db_Expr('x.occurred_at')),
                'stream' => 'x.stream',
                'verb' => 'x.verb',
                'record_key' => 'x.key_json',
                'lane' => 'x.lane',
                'attempts' => 'x.attempts',
                'next_attempt_at' => new \Zend_Db_Expr('NULL'),
                'detail' => 'x.reason',
                'idempotency_key' => 'x.idempotency_key',
            ]);
    }

    /**
     * "2026-11-18T02:55:02Z" as a UTC date the grid can filter by range and show in the admin time zone.
     */
    private function isoToDatetime(\Zend_Db_Expr $value): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr("STR_TO_DATE(LEFT(" . $value . ", 19), '%Y-%m-%dT%H:%i:%s')");
    }
}
