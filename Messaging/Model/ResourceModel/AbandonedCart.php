<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel;

use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Throwable;

class AbandonedCart extends AbstractDb
{
    public const TABLE = 'centraliza_sales_abandoned_cart';

    /**
     * Columns a selection row carries; everything else keeps the table default.
     */
    private const PENDING_COLUMNS = [
        AbandonedCartInterface::QUOTE_ID,
        AbandonedCartInterface::STORE_ID,
        AbandonedCartInterface::WEBSITE_ID,
        AbandonedCartInterface::REMINDER_NUMBER,
        AbandonedCartInterface::STATUS,
        AbandonedCartInterface::ATTEMPTS,
        AbandonedCartInterface::QUOTE_UPDATED_AT,
        AbandonedCartInterface::LAST_ACTIVITY_AT,
    ];

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, AbandonedCartInterface::ENTITY_ID);
    }

    /**
     * Insert selected sends as pending, skipping any send of the cart already recorded.
     *
     * @param array $rows
     * @return int
     */
    public function insertPending(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $data = [];
        foreach ($rows as $row) {
            // Null rather than a stand-in date when the selection carried none: a row that records no wait
            // reads as unknown, where a filled-in one would read as a wait that was really applied.
            $lastActivityAt = $row[AbandonedCartInterface::LAST_ACTIVITY_AT] ?? null;
            if (empty($lastActivityAt)) {
                $lastActivityAt = null;
            }

            $data[] = [
                (int) $row[AbandonedCartInterface::QUOTE_ID],
                (int) $row[AbandonedCartInterface::STORE_ID],
                (int) $row[AbandonedCartInterface::WEBSITE_ID],
                (int) ($row[AbandonedCartInterface::REMINDER_NUMBER] ?? 0),
                AbandonedCartInterface::STATUS_PENDING,
                0,
                (string) $row[AbandonedCartInterface::QUOTE_UPDATED_AT],
                $lastActivityAt,
            ];
        }

        // INSERT IGNORE on the (quote_id, reminder_number) unique key: insertOnDuplicate() would put a
        // success row back to pending and send the same message to the customer again.
        return (int) $this->getConnection()->insertArray(
            $this->getMainTable(),
            self::PENDING_COLUMNS,
            $data,
            AdapterInterface::INSERT_IGNORE
        );
    }

    /**
     * Take the website's next row ready to send and mark it processing in one transaction, so no two runs get it.
     *
     * @param string $now
     * @param int $websiteId
     * @param int[] $excludedIds rows already handled in this round, so a row given back is not taken again
     * @return array|null the row; its attempts is the reservation token later writes pass back
     */
    public function reserveNext(string $now, int $websiteId, array $excludedIds = []): ?array
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();

        try {
            $select = $connection->select()
                ->from($this->getMainTable())
                ->where('website_id = ?', $websiteId)
                ->where(
                    '(' . $connection->quoteInto('status = ?', AbandonedCartInterface::STATUS_PENDING)
                    . ' AND (next_attempt_at IS NULL OR ' . $connection->quoteInto('next_attempt_at <= ?', $now) . '))'
                    . ' OR (' . $connection->quoteInto('status = ?', AbandonedCartInterface::STATUS_RETRY)
                    . ' AND ' . $connection->quoteInto('next_attempt_at <= ?', $now) . ')'
                )
                ->order('entity_id ASC')
                ->limit(1)
                ->forUpdate(true);
            if (!empty($excludedIds)) {
                $select->where('entity_id NOT IN (?)', $excludedIds);
            }
            $row = $connection->fetchRow($select);

            if (empty($row)) {
                $connection->commit();

                return null;
            }

            // updated_at set here is when processing started, which the expired-reservation release reads.
            $connection->update(
                $this->getMainTable(),
                [
                    AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_PROCESSING,
                    AbandonedCartInterface::UPDATED_AT => $now,
                ],
                ['entity_id = ?' => (int) $row[AbandonedCartInterface::ENTITY_ID]]
            );
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $row['reserved_from'] = $row[AbandonedCartInterface::STATUS];
        $row[AbandonedCartInterface::STATUS] = AbandonedCartInterface::STATUS_PROCESSING;

        return $row;
    }

    /**
     * Give a reserved row back without spending an attempt.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $status
     * @param string|null $nextAttemptAt
     * @return bool false when the row was no longer processing under this reservation
     */
    public function releaseReservation(
        int $entityId,
        int $reservedAttempts,
        string $status,
        ?string $nextAttemptAt
    ): bool {
        return $this->updateReservedRow($entityId, $reservedAttempts, [
            AbandonedCartInterface::STATUS => $status,
            AbandonedCartInterface::NEXT_ATTEMPT_AT => $nextAttemptAt,
        ]);
    }

    /**
     * Put a reserved row whose cart was touched again back to pending until it is abandoned again.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $nextAttemptAt
     * @param string $quoteUpdatedAt
     * @param string $lastActivityAt activity that postponed the row, empty when unknown
     * @return bool false when the row was no longer processing under this reservation
     */
    public function postponeReservation(
        int $entityId,
        int $reservedAttempts,
        string $nextAttemptAt,
        string $quoteUpdatedAt,
        string $lastActivityAt = ''
    ): bool {
        $data = [
            AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_PENDING,
            AbandonedCartInterface::NEXT_ATTEMPT_AT => $nextAttemptAt,
            AbandonedCartInterface::QUOTE_UPDATED_AT => $quoteUpdatedAt,
        ];

        // The row is postponed precisely because the customer came back, so the activity it shows must move with
        // the cart version: leaving it at the selection's value would claim the customer idle since before the
        // activity that postponed the row.
        if (!empty($lastActivityAt)) {
            $data[AbandonedCartInterface::LAST_ACTIVITY_AT] = $lastActivityAt;
        }

        return $this->updateReservedRow($entityId, $reservedAttempts, $data);
    }

    /**
     * Mark a reserved row accepted by Centraliza; the attempt counts.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $now
     * @param string $quoteUpdatedAt updated_at of the quote version the message carried
     * @param string $cartSignature signature of the content the message carried, empty when unknown
     * @param string $lastActivityAt activity seen when the message was built, empty when unknown
     * @return bool false when the row was no longer processing under this reservation
     */
    public function markSuccess(
        int $entityId,
        int $reservedAttempts,
        string $now,
        string $quoteUpdatedAt,
        string $cartSignature = '',
        string $lastActivityAt = ''
    ): bool {
        // 200: the Base client does not expose the exact 2xx code.
        $data = [
            AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_SUCCESS,
            AbandonedCartInterface::ATTEMPTS => new Expression('attempts + 1'),
            AbandonedCartInterface::NEXT_ATTEMPT_AT => null,
            AbandonedCartInterface::LAST_ATTEMPT_AT => $now,
            AbandonedCartInterface::LAST_HTTP_STATUS => 200,
            AbandonedCartInterface::LAST_ERROR => null,
            AbandonedCartInterface::SENT_AT => $now,
        ];

        // A resend follows a change after this version, so the row keeps the version that was sent, not the one
        // seen at selection.
        if (!empty($quoteUpdatedAt)) {
            $data[AbandonedCartInterface::QUOTE_UPDATED_AT] = $quoteUpdatedAt;
        }

        // A resend needs different content from the one sent; without a signature the next selection compares by
        // update time alone.
        if (!empty($cartSignature)) {
            $data[AbandonedCartInterface::CART_SIGNATURE] = $cartSignature;
        }

        // Kept together with the version above: both describe the cart the message carried, and a row showing one
        // from the send and the other from the selection reads as a customer idle since before their own change.
        if (!empty($lastActivityAt)) {
            $data[AbandonedCartInterface::LAST_ACTIVITY_AT] = $lastActivityAt;
        }

        return $this->updateReservedRow($entityId, $reservedAttempts, $data);
    }

    /**
     * Put a reserved row back to wait for a resend.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $nextAttemptAt
     * @param bool $countsAttempt false for a 429, which must not use up the cart's retries
     * @param int|null $httpStatus
     * @param string $error
     * @param string $now
     * @return bool false when the row was no longer processing under this reservation
     */
    public function markRetry(
        int $entityId,
        int $reservedAttempts,
        string $nextAttemptAt,
        bool $countsAttempt,
        ?int $httpStatus,
        string $error,
        string $now
    ): bool {
        $attempts = new Expression('attempts');
        if ($countsAttempt) {
            $attempts = new Expression('attempts + 1');
        }

        return $this->updateReservedRow($entityId, $reservedAttempts, [
            AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_RETRY,
            AbandonedCartInterface::ATTEMPTS => $attempts,
            AbandonedCartInterface::NEXT_ATTEMPT_AT => $nextAttemptAt,
            AbandonedCartInterface::LAST_ATTEMPT_AT => $now,
            AbandonedCartInterface::LAST_HTTP_STATUS => $httpStatus,
            AbandonedCartInterface::LAST_ERROR => $error,
        ]);
    }

    /**
     * Mark a reserved row failed for good; the attempt counts.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param int|null $httpStatus
     * @param string $error
     * @param string $now
     * @return bool false when the row was no longer processing under this reservation
     */
    public function markFailed(int $entityId, int $reservedAttempts, ?int $httpStatus, string $error, string $now): bool
    {
        return $this->updateReservedRow($entityId, $reservedAttempts, [
            AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_FAILED,
            AbandonedCartInterface::ATTEMPTS => new Expression('attempts + 1'),
            AbandonedCartInterface::NEXT_ATTEMPT_AT => null,
            AbandonedCartInterface::LAST_ATTEMPT_AT => $now,
            AbandonedCartInterface::LAST_HTTP_STATUS => $httpStatus,
            AbandonedCartInterface::LAST_ERROR => $error,
        ]);
    }

    /**
     * Mark a reserved row failed for good without a request; attempts and the last attempt stay as they were.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @param string $error
     * @return bool false when the row was no longer processing under this reservation
     */
    public function markFailedWithoutAttempt(int $entityId, int $reservedAttempts, string $error): bool
    {
        return $this->updateReservedRow($entityId, $reservedAttempts, [
            AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_FAILED,
            AbandonedCartInterface::NEXT_ATTEMPT_AT => null,
            AbandonedCartInterface::LAST_ERROR => $error,
        ]);
    }

    /**
     * Delete a reserved row whose cart must no longer be sent.
     *
     * @param int $entityId
     * @param int $reservedAttempts attempts the row held when it was reserved
     * @return bool false when the row was no longer processing under this reservation
     */
    public function deleteReservation(int $entityId, int $reservedAttempts): bool
    {
        return (int) $this->getConnection()->delete(
            $this->getMainTable(),
            $this->reservedRowCondition($entityId, $reservedAttempts)
        ) > 0;
    }

    /**
     * Rows left in processing since before the given time.
     *
     * @param string $before
     * @return array
     */
    public function findExpiredReservations(string $before): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('status = ?', AbandonedCartInterface::STATUS_PROCESSING)
            ->where('updated_at <= ?', $before)
            ->order('entity_id ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Mark an expired reservation failed for good; the attempt counts.
     *
     * @param int $entityId
     * @param int $reservedAttempts
     * @param string $reservedBefore
     * @param string $error
     * @param string $now
     * @return bool false when the row was no longer the expired reservation that was read
     */
    public function markExpiredReservationFailed(
        int $entityId,
        int $reservedAttempts,
        string $reservedBefore,
        string $error,
        string $now
    ): bool {
        // A row reserved again since it was read has a newer updated_at, which keeps this write off it.
        $condition = $this->reservedRowCondition($entityId, $reservedAttempts);
        $condition['updated_at <= ?'] = $reservedBefore;

        return (int) $this->getConnection()->update(
            $this->getMainTable(),
            [
                AbandonedCartInterface::STATUS => AbandonedCartInterface::STATUS_FAILED,
                AbandonedCartInterface::ATTEMPTS => new Expression('attempts + 1'),
                AbandonedCartInterface::NEXT_ATTEMPT_AT => null,
                AbandonedCartInterface::LAST_ATTEMPT_AT => $now,
                AbandonedCartInterface::LAST_HTTP_STATUS => null,
                AbandonedCartInterface::LAST_ERROR => $error,
            ],
            $condition
        ) > 0;
    }

    /**
     * @param int $entityId
     * @param int $reservedAttempts
     * @param array $data
     * @return bool
     */
    private function updateReservedRow(int $entityId, int $reservedAttempts, array $data): bool
    {
        return (int) $this->getConnection()->update(
            $this->getMainTable(),
            $data,
            $this->reservedRowCondition($entityId, $reservedAttempts)
        ) > 0;
    }

    /**
     * A write lands only while the row is still processing under the same reservation.
     *
     * @param int $entityId
     * @param int $reservedAttempts
     * @return array
     */
    private function reservedRowCondition(int $entityId, int $reservedAttempts): array
    {
        return [
            'entity_id = ?' => $entityId,
            'status = ?' => AbandonedCartInterface::STATUS_PROCESSING,
            'attempts = ?' => $reservedAttempts,
        ];
    }
}
