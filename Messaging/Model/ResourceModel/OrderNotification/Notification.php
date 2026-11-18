<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification;

use Centraliza\Connector\Messaging\Model\OrderNotification\Notification as NotificationModel;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime as DateTimeFormat;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Throwable;

/**
 * A write after a reservation applies only while the row is still in sending under it, and reports whether it did.
 */
class Notification extends AbstractDb
{
    private const TABLE = 'centraliza_notification';

    /**
     * Same bound as the last_error_message column.
     */
    private const ERROR_MESSAGE_MAX_LENGTH = 500;

    /**
     * @param Context $context
     * @param DateTime $dateTime
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly DateTime $dateTime,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'entity_id');
    }

    /**
     * Record a new notification waiting to be sent.
     *
     * @param array $row column values, without status and timestamps
     * @return void
     */
    public function insertPending(array $row): void
    {
        $now = $this->now();
        $this->getConnection()->insert($this->getMainTable(), $row + [
            'status' => NotificationModel::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Websites holding at least one row ready to send, ascending.
     *
     * @param string $now
     * @return int[]
     */
    public function getWebsiteIdsReadyToSend(string $now): array
    {
        $select = $this->selectReadyToSend($now)
            ->reset(Select::COLUMNS)
            ->columns(['website_id'])
            ->distinct(true)
            ->order('notification.website_id ASC');

        return array_map('intval', $this->getConnection()->fetchCol($select));
    }

    /**
     * Take the website's next row ready to send and mark it sending in one transaction, so no two runners get it.
     *
     * @param string $now
     * @param int $websiteId
     * @return array|null the row, with reserved_from (its previous status) and attempts (the reservation token)
     */
    public function reserveNext(string $now, int $websiteId): ?array
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();

        try {
            $select = $this->selectReadyToSend($now)
                ->where('notification.website_id = ?', $websiteId)
                ->order('notification.entity_id ASC')
                ->limit(1)
                ->forUpdate(true);
            $row = $connection->fetchRow($select);

            if (empty($row)) {
                $connection->commit();

                return null;
            }

            $connection->update(
                $this->getMainTable(),
                ['status' => NotificationModel::STATUS_SENDING, 'updated_at' => $this->now()],
                ['entity_id = ?' => (int) $row['entity_id']]
            );
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $row['reserved_from'] = $row['status'];
        $row['status'] = NotificationModel::STATUS_SENDING;

        return $row;
    }

    /**
     * Put a reserved row back to wait without recording anything else.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $status
     * @param string|null $nextAttemptAt
     * @return bool false when the row was no longer in sending
     */
    public function releaseReservation(int $id, int $reservedAttempts, string $status, ?string $nextAttemptAt): bool
    {
        return $this->updateReservedRow(
            $id,
            $reservedAttempts,
            ['status' => $status, 'next_attempt_at' => $nextAttemptAt]
        );
    }

    /**
     * Put a reserved row back to wait, recording why, without spending an attempt.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $status
     * @param string $nextAttemptAt
     * @param int $errorCode
     * @param string $message
     * @return bool false when the row was no longer in sending
     */
    public function postponeReservation(
        int $id,
        int $reservedAttempts,
        string $status,
        string $nextAttemptAt,
        int $errorCode,
        string $message
    ): bool {
        return $this->updateReservedRow($id, $reservedAttempts, [
            'status' => $status,
            'next_attempt_at' => $nextAttemptAt,
            'last_error_code' => $errorCode,
            'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * Record that Centraliza accepted the reserved row, also after its expired reservation was marked unknown.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string|null $externalId null when the answer carried no usable id
     * @return bool false when the row is neither in sending under this reservation nor unknown since it
     */
    public function markDelivered(int $id, int $reservedAttempts, ?string $externalId): bool
    {
        // Only the expired-reservation release moves a row on while its request runs, and it marks it unknown.
        $connection = $this->getConnection();
        $sameReservation = $connection->quoteInto('status = ?', NotificationModel::STATUS_SENDING)
            . ' AND ' . $connection->quoteInto('attempts = ?', $reservedAttempts);
        $unknownSinceThisReservation = $connection->quoteInto('status = ?', NotificationModel::STATUS_UNKNOWN)
            . ' AND ' . $connection->quoteInto('attempts > ?', $reservedAttempts);

        return (int) $connection->update(
            $this->getMainTable(),
            [
                'status' => NotificationModel::STATUS_DELIVERED,
                'external_id' => $externalId,
                'delivered_at' => $this->now(),
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_at' => $this->now(),
            ],
            [
                'entity_id = ?' => $id,
                '(' . $sameReservation . ') OR (' . $unknownSinceThisReservation . ')',
            ]
        ) > 0;
    }

    /**
     * Record that the reserved row's request may have reached Centraliza without an answer; the send counts.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param int|null $errorCode null when the failure came from Magento, with no answer from Centraliza
     * @param string $message
     * @return bool false when the row was no longer in sending
     */
    public function markUnknown(int $id, int $reservedAttempts, ?int $errorCode, string $message): bool
    {
        return $this->updateReservedRow($id, $reservedAttempts, [
            'status' => NotificationModel::STATUS_UNKNOWN,
            'attempts' => new Expression('attempts + 1'),
            'next_attempt_at' => null,
            'last_error_code' => $errorCode,
            'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * Mark unknown a row left in sending by a process that died; the send counts.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $expiredBefore the reservation must still be older than this
     * @param string $message
     * @return bool false when the row was no longer in sending under that expired reservation
     */
    public function markExpiredReservationUnknown(
        int $id,
        int $reservedAttempts,
        string $expiredBefore,
        string $message
    ): bool {
        // Age checked again: a row put back without an attempt and reserved again keeps its count.
        return $this->updateReservedRow(
            $id,
            $reservedAttempts,
            [
                'status' => NotificationModel::STATUS_UNKNOWN,
                'attempts' => new Expression('attempts + 1'),
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
            ],
            ['updated_at < ?' => $expiredBefore]
        );
    }

    /**
     * Schedule the reserved row for another send, spending one attempt.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param int|null $errorCode null when the send failed locally, with no answer from Centraliza
     * @param string $message
     * @param string $nextAttemptAt
     * @return bool false when the row was no longer in sending
     */
    public function markRetry(
        int $id,
        int $reservedAttempts,
        ?int $errorCode,
        string $message,
        string $nextAttemptAt
    ): bool {
        return $this->updateReservedRow($id, $reservedAttempts, [
            'status' => NotificationModel::STATUS_RETRY,
            'attempts' => new Expression('attempts + 1'),
            'next_attempt_at' => $nextAttemptAt,
            'last_error_code' => $errorCode,
            'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * Fail the reserved row: on its last allowed send, or without a request (expired, never reached Centraliza).
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param int|null $errorCode null when the send failed locally, with no answer from Centraliza
     * @param string $message
     * @param bool $countsAttempt true when this send counts against the attempt limit
     * @return bool false when the row was no longer in sending
     */
    public function markFailed(
        int $id,
        int $reservedAttempts,
        ?int $errorCode,
        string $message,
        bool $countsAttempt
    ): bool {
        $attempts = new Expression('attempts');
        if ($countsAttempt) {
            $attempts = new Expression('attempts + 1');
        }

        return $this->updateReservedRow($id, $reservedAttempts, [
            'status' => NotificationModel::STATUS_FAILED,
            'attempts' => $attempts,
            'next_attempt_at' => null,
            'last_error_code' => $errorCode,
            'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
        ]);
    }

    /**
     * Fail a website's waiting rows created before the cut, without a send being counted.
     *
     * @param int $websiteId
     * @param string $createdBefore
     * @param string $message
     * @return int rows failed
     */
    public function failWaitingCreatedBefore(int $websiteId, string $createdBefore, string $message): int
    {
        return (int) $this->getConnection()->update(
            $this->getMainTable(),
            [
                'status' => NotificationModel::STATUS_FAILED,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_message' => mb_substr($message, 0, self::ERROR_MESSAGE_MAX_LENGTH),
                'updated_at' => $this->now(),
            ],
            [
                'website_id = ?' => $websiteId,
                'status IN (?)' => [NotificationModel::STATUS_PENDING, NotificationModel::STATUS_RETRY],
                'created_at < ?' => $createdBefore,
            ]
        );
    }

    /**
     * Reservations left in sending since before the cut, oldest first.
     *
     * @param string $before
     * @return array[] entity_id, website_id and attempts of each row
     */
    public function findExpiredReservations(string $before): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['entity_id', 'website_id', 'attempts'])
            ->where('status = ?', NotificationModel::STATUS_SENDING)
            ->where('updated_at < ?', $before)
            ->order('entity_id ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Find the row Centraliza knows by the given id.
     *
     * @param string $externalId
     * @return array|null entity_id, external_id, status and increment_id
     */
    public function findByExternalId(string $externalId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable(), ['entity_id', 'external_id', 'status', 'increment_id'])
                ->where('external_id = ?', $externalId)
        );

        if (empty($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Record the callback only while the row is still in a state a callback may leave.
     *
     * @param int $id
     * @param bool $sent
     * @return bool false when another request already finalized the row
     */
    public function markCallback(int $id, bool $sent): bool
    {
        $now = $this->now();
        $status = NotificationModel::STATUS_NOT_SENT;
        if ($sent) {
            $status = NotificationModel::STATUS_SENT;
        }

        return (int) $this->getConnection()->update(
            $this->getMainTable(),
            [
                'status' => $status,
                'sent_at' => $now,
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $id, 'status IN (?)' => NotificationModel::CALLBACK_SOURCE_STATUSES]
        ) > 0;
    }

    /**
     * Delete final rows last changed before the cut, oldest first, in one bounded batch.
     *
     * @param string $before
     * @param int $limit
     * @return int rows deleted
     */
    public function deleteFinishedBefore(string $before, int $limit): int
    {
        $connection = $this->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), ['entity_id'])
                ->where('status IN (?)', NotificationModel::FINAL_STATUSES)
                ->where('updated_at < ?', $before)
                ->order('entity_id ASC')
                ->limit($limit)
        );

        if (empty($ids)) {
            return 0;
        }

        // Checked again: a row resent between the select and the delete must not be deleted.
        return (int) $connection->delete($this->getMainTable(), [
            'entity_id IN (?)' => $ids,
            'status IN (?)' => NotificationModel::FINAL_STATUSES,
            'updated_at < ?' => $before,
        ]);
    }

    /**
     * Delete delivered rows accepted before the cut, oldest first, in one bounded batch.
     *
     * @param string $before
     * @param int $limit
     * @return int rows deleted
     */
    public function deleteDeliveredBefore(string $before, int $limit): int
    {
        // Aged from the acceptance, when the wait for the callback starts; created_at when delivered_at is missing.
        $connection = $this->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), ['entity_id'])
                ->where('status = ?', NotificationModel::STATUS_DELIVERED)
                ->where('(delivered_at < ?) OR (delivered_at IS NULL AND created_at < ?)', $before)
                ->order('entity_id ASC')
                ->limit($limit)
        );

        if (empty($ids)) {
            return 0;
        }

        // Checked again: a row a callback finalized meanwhile follows the other retention rule.
        return (int) $connection->delete(
            $this->getMainTable(),
            ['entity_id IN (?)' => $ids, 'status = ?' => NotificationModel::STATUS_DELIVERED]
        );
    }

    /**
     * Read what deciding a resend needs from the given rows.
     *
     * @param int[] $ids
     * @return array[] entity_id, website_id, status, last_error_code and created_at of each row found
     */
    public function getResendCandidates(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $connection = $this->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->getMainTable(),
                    ['entity_id', 'website_id', 'status', 'last_error_code', 'created_at']
                )
                ->where('entity_id IN (?)', array_map('intval', $ids))
        );
    }

    /**
     * Schedule the given rows for a send now, with a fresh attempt limit.
     *
     * @param int[] $ids
     * @return int rows scheduled; a row that left the resendable states in the meantime is not
     */
    public function scheduleResend(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $now = $this->now();

        // State and code checked again: the row may have been sent and failed for good since it was read.
        $connection = $this->getConnection();
        $stillResendable = $connection->quoteInto('status = ?', NotificationModel::STATUS_NOT_SENT)
            . ' OR (' . $connection->quoteInto('status = ?', NotificationModel::STATUS_FAILED)
            . ' AND (last_error_code IS NULL OR '
            . $connection->quoteInto('last_error_code IN (?)', NotificationModel::RETRYABLE_CODES) . '))';

        // A full attempt limit again, safe since a failed or not_sent row holds no live reservation; the
        // old external id goes so a repeated callback for it cannot finalize the new send.
        return (int) $connection->update(
            $this->getMainTable(),
            [
                'status' => NotificationModel::STATUS_RETRY,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'external_id' => null,
                'delivered_at' => null,
                'sent_at' => null,
                'updated_at' => $now,
            ],
            [
                'entity_id IN (?)' => array_map('intval', $ids),
                '(' . $stillResendable . ')',
            ]
        );
    }

    /**
     * Rows waiting to be sent whose due time has come.
     *
     * @param string $now
     * @return Select
     */
    private function selectReadyToSend(string $now): Select
    {
        $connection = $this->getConnection();

        // The customer gets an order's statuses in the order they happened ("paid" before "shipped"): a row
        // waits while an earlier one of the same order is pending, retry or sending. Any other state releases
        // it, sent or not (delivered, unknown, failed): otherwise one lost message would block the order for good.
        $earlierWaiting = $connection->select()
            ->from(['earlier_notification' => $this->getMainTable()], [new Expression('1')])
            ->where('earlier_notification.order_id = notification.order_id')
            ->where('earlier_notification.entity_id < notification.entity_id')
            ->where('earlier_notification.status IN (?)', [
                NotificationModel::STATUS_PENDING,
                NotificationModel::STATUS_RETRY,
                NotificationModel::STATUS_SENDING,
            ]);

        return $connection->select()
            ->from(['notification' => $this->getMainTable()])
            ->where('notification.status IN (?)', [NotificationModel::STATUS_PENDING, NotificationModel::STATUS_RETRY])
            ->where('notification.next_attempt_at IS NULL OR notification.next_attempt_at <= ?', $now)
            ->where('NOT EXISTS ?', $earlierWaiting);
    }

    /**
     * Write a reserved row only while it is still in sending under the same reservation.
     *
     * @param int $id
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param array $data
     * @param array $extraConditions further conditions the row must meet, as a where array
     * @return bool false when the row was no longer in sending under that reservation
     */
    private function updateReservedRow(int $id, int $reservedAttempts, array $data, array $extraConditions = []): bool
    {
        // attempts is the reservation token: every write that spends an attempt raises it.
        return (int) $this->getConnection()->update(
            $this->getMainTable(),
            $data + ['updated_at' => $this->now()],
            [
                'entity_id = ?' => $id,
                'status = ?' => NotificationModel::STATUS_SENDING,
                'attempts = ?' => $reservedAttempts,
            ] + $extraConditions
        ) > 0;
    }

    /**
     * @return string
     */
    private function now(): string
    {
        return $this->dateTime->gmtDate(DateTimeFormat::DATETIME_PHP_FORMAT);
    }
}
