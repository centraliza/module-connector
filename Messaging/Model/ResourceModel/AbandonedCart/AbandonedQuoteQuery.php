<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart;

use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart\CartActivity;
use Centraliza\Connector\Messaging\Model\ResourceModel\DatabaseClock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

class AbandonedQuoteQuery
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param DatabaseClock $databaseClock
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DatabaseClock $databaseClock
    ) {
    }

    /**
     * Active, non-empty customer quotes of the store view abandoned inside the range and never selected.
     *
     * @param int $storeId
     * @param string $from
     * @param string $to
     * @param string $activityFrom oldest activity a cart may have and still be sent
     * @param int $afterQuoteId
     * @param int $limit
     * @return array<int, string> quote id => last activity that made it abandoned
     */
    public function findQuoteIds(
        int $storeId,
        string $from,
        string $to,
        string $activityFrom,
        int $afterQuoteId,
        int $limit
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $abandonedCartTable = $this->resourceConnection->getTableName(AbandonedCartResource::TABLE);
        // Read once and reused: the clock difference it carries is refreshed over time, and the date
        // selected on must be the same one the row records.
        $lastActivityExpression = $this->getLastActivityExpression();

        $alreadySelected = $connection->select()
            ->from(['abandoned_cart' => $abandonedCartTable], []);

        $select = $connection->select()
            ->from(
                ['quote' => $quoteTable],
                ['entity_id', 'last_activity_at' => new Expression($lastActivityExpression)]
            )
            ->where('quote.store_id = ?', $storeId)
            ->where('quote.is_active = ?', 1)
            ->where('quote.items_count > ?', 0)
            // Magento's quote lifetime, not the send limit: kept so the store/updated_at index bounds the range.
            ->where('quote.updated_at >= ?', $from)
            ->where('quote.updated_at <= ?', $to)
            // Abandoned counts from the customer's last activity: a customer still browsing the checkout has
            // not abandoned the cart, even if the cart itself did not change.
            ->where($lastActivityExpression . ' <= ?', $to)
            ->where('quote.entity_id > ?', $afterQuoteId)
            // Logged-in customers only: anyone can put any telephone on a guest cart through the public
            // cart API and make the store send WhatsApp messages to numbers of their choosing.
            ->where('quote.customer_id IS NOT NULL')
            ->where('quote.customer_is_guest = ?', 0)
            ->order('quote.entity_id ASC')
            ->limit($limit);
        // Too old to be worth a message, counted from what the customer did with this cart: one they opened an
        // hour ago is not idle, however old its last change is.
        if (!empty($activityFrom)) {
            $select->where($this->getCartActivityExpression() . ' >= ?', $activityFrom);
        }
        $select->exists($alreadySelected, 'abandoned_cart.quote_id = quote.entity_id', false);

        $lastActivityAt = [];
        foreach ($connection->fetchPairs($select) as $quoteId => $activityAt) {
            $lastActivityAt[(int) $quoteId] = (string) $activityAt;
        }

        return $lastActivityAt;
    }

    /**
     * Quotes of the store view already sent, returned to after that send and abandoned again, with the next
     * send number.
     *
     * @param int $storeId
     * @param int $maxNotifications messages per cart including the first; 0 means unlimited
     * @param string $from
     * @param string $to
     * @param string $activityFrom oldest activity a cart may have and still be sent
     * @param int $afterQuoteId
     * @param int $limit
     * @return array<int, array<string, int|string>> quote id => reminder number and last activity of the new row
     */
    public function findResendQuoteIds(
        int $storeId,
        int $maxNotifications,
        string $from,
        string $to,
        string $activityFrom,
        int $afterQuoteId,
        int $limit
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $abandonedCartTable = $this->resourceConnection->getTableName(AbandonedCartResource::TABLE);
        // Read once and reused, as on the first send: the row must record the date it was selected on.
        $lastActivityExpression = $this->getLastActivityExpression();

        $select = $connection->select()
            ->from(
                ['last_send' => $abandonedCartTable],
                [
                    'quote_id',
                    'next_reminder_number' => new Expression('last_send.reminder_number + 1'),
                    'last_activity_at' => new Expression($lastActivityExpression),
                ]
            )
            ->join(['quote' => $quoteTable], 'quote.entity_id = last_send.quote_id', [])
            ->joinLeft(
                ['later_send' => $abandonedCartTable],
                'later_send.quote_id = last_send.quote_id AND later_send.reminder_number > last_send.reminder_number',
                []
            )
            ->where('later_send.entity_id IS NULL')
            // Only after an accepted send: a new message is never stacked on one the customer may not have received.
            ->where('last_send.status = ?', AbandonedCartInterface::STATUS_SUCCESS)
            // Two ways a cart earns another message, and it needs only one of them.
            //
            // The content changed: the cart was saved after the version the last message carried (both dates come
            // from quote.updated_at, so no clock difference can hide or invent a change) AND its items or
            // quantities differ from what that message carried. The signature is what makes this a real change:
            // logging in, an admin price rule action or a recollect also save the quote without the customer
            // touching it. A row sent before signatures existed has none and is compared by update time alone.
            //
            // Or the customer came back to look: they opened the cart or the checkout page after the message went
            // out. A customer who returns to an unchanged cart and leaves again has shown the same intent as one
            // who edits it, and until now no message followed that. Only those two pages count, never the
            // customer's visits at large: a login or any form post anywhere on the store would revive every cart
            // the customer ever left, which is not a sign of interest in this one.
            //
            // What keeps this from becoming a stream: the total of messages per cart is the only ceiling, and the
            // abandonment time the only spacing, since each return has to be followed by that wait. A store that
            // sets the total to unlimited is choosing one message per visit the customer ends.
            ->where(
                '((quote.updated_at > last_send.quote_updated_at'
                . ' AND (last_send.cart_signature IS NULL OR last_send.cart_signature <> '
                . $this->getCartSignatureExpression() . '))'
                . ' OR ' . $this->getLastCartViewExpression() . ' > last_send.sent_at)'
            )
            ->where('quote.store_id = ?', $storeId)
            ->where('quote.is_active = ?', 1)
            ->where('quote.items_count > ?', 0)
            ->where('quote.customer_id IS NOT NULL')
            ->where('quote.customer_is_guest = ?', 0)
            ->where('quote.updated_at >= ?', $from)
            ->where('quote.updated_at <= ?', $to)
            // Abandoned again counts from the customer's last activity, as for the first send.
            ->where($lastActivityExpression . ' <= ?', $to)
            ->where('quote.entity_id > ?', $afterQuoteId)
            ->order('quote.entity_id ASC')
            ->limit($limit);
        // The same idle bound as the first send, on the cart's own activity.
        if (!empty($activityFrom)) {
            $select->where($this->getCartActivityExpression() . ' >= ?', $activityFrom);
        }
        // reminder_number counts from 0 (the first send), so the next one must stay under the total allowed.
        if (!empty($maxNotifications)) {
            $select->where('last_send.reminder_number + 1 < ?', $maxNotifications);
        }

        $resends = [];
        foreach ($connection->fetchAll($select) as $row) {
            $resends[(int) $row['quote_id']] = [
                AbandonedCartInterface::REMINDER_NUMBER => (int) $row['next_reminder_number'],
                AbandonedCartInterface::LAST_ACTIVITY_AT => (string) $row['last_activity_at'],
            ];
        }

        return $resends;
    }

    /**
     * The cart's own last activity, in UTC: the latest of its last change and its last cart or checkout view.
     *
     * @param int $quoteId
     * @return string|null null when the quote no longer exists
     */
    public function getCartActivityAt(int $quoteId): ?string
    {
        return $this->fetchActivity($quoteId, $this->getCartActivityExpression());
    }

    /**
     * The quote's last activity, in UTC: the latest of its last change, its customer's last visit and its last
     * cart or checkout view.
     *
     * @param int $quoteId
     * @return string|null null when the quote no longer exists
     */
    public function getLastActivityAt(int $quoteId): ?string
    {
        return $this->fetchActivity($quoteId, $this->getLastActivityExpression());
    }

    /**
     * @param int $quoteId
     * @param string $expression
     * @return string|null
     */
    private function fetchActivity(int $quoteId, string $expression): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $lastActivityAt = $connection->fetchOne(
            $connection->select()
                ->from(
                    ['quote' => $this->resourceConnection->getTableName('quote')],
                    ['last_activity_at' => new Expression($expression)]
                )
                ->where('quote.entity_id = ?', $quoteId)
        );
        if (empty($lastActivityAt)) {
            return null;
        }

        return (string) $lastActivityAt;
    }

    /**
     * Signature of the quote's current content, the same formula stored on a sent row.
     *
     * @param int $quoteId
     * @return string|null null when the quote no longer exists or has no item
     */
    public function getCartSignature(int $quoteId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $signature = $connection->fetchOne(
            $connection->select()
                ->from(
                    ['quote' => $this->resourceConnection->getTableName('quote')],
                    ['cart_signature' => new Expression($this->getCartSignatureExpression())]
                )
                ->where('quote.entity_id = ?', $quoteId)
        );
        if (empty($signature)) {
            return null;
        }

        return (string) $signature;
    }

    /**
     * SHA-256 of the quote's visible items as sku:qty, sorted by sku.
     *
     * @return string
     */
    private function getCartSignatureExpression(): string
    {
        // The item count, the total quantity and a sum of per-item checksums lead the hashed text:
        // GROUP_CONCAT stops at the server's group_concat_max_len, and they still tell a long cart apart
        // when only its tail changed.
        return '(SELECT SHA2(CONCAT(COUNT(*), \'|\', CAST(SUM(cart_item.qty) AS DECIMAL(12,4)), \'|\', '
            . 'SUM(CRC32(CONCAT(cart_item.sku, \':\', CAST(cart_item.qty AS DECIMAL(12,4))))), \'|\', '
            . 'GROUP_CONCAT(CONCAT(cart_item.sku, \':\', CAST(cart_item.qty AS DECIMAL(12,4))) '
            . 'ORDER BY cart_item.sku, cart_item.item_id SEPARATOR \',\')), 256) FROM '
            . $this->resourceConnection->getTableName('quote_item')
            . ' AS cart_item WHERE cart_item.quote_id = quote.entity_id AND cart_item.parent_item_id IS NULL)';
    }

    /**
     * The latest of the cart's last change, the customer's last visit and the last cart or checkout view.
     *
     * @return string
     */
    private function getLastActivityExpression(): string
    {
        return 'GREATEST(quote.updated_at'
            . ', COALESCE(' . $this->getLastVisitExpression() . ', quote.updated_at)'
            . ', COALESCE(' . $this->getLastCartViewExpression() . ', quote.updated_at))';
    }

    /**
     * The cart's own activity, in UTC: the latest of its last change and its last cart or checkout view.
     *
     * @return string
     */
    private function getCartActivityExpression(): string
    {
        // Deliberately without the customer's visits, unlike the expression above. A visit only ever delays a
        // message, and that is safe in any direction it is wrong. The idle limit works the other way round: it
        // decides a cart is still worth a message, and a customer who logs in anywhere on the store would revive
        // every cart they ever left, which is the one thing that limit exists to prevent. Only what the customer
        // did with this cart may keep it alive.
        return 'GREATEST(quote.updated_at'
            . ', COALESCE(' . $this->getLastCartViewExpression() . ', quote.updated_at))';
    }

    /**
     * The customer's last view of this cart or of the checkout, as recorded by the module.
     *
     * @return string
     */
    private function getLastCartViewExpression(): string
    {
        return '(SELECT cart_activity.last_activity_at FROM '
            . $this->resourceConnection->getTableName(CartActivity::TABLE)
            . ' AS cart_activity WHERE cart_activity.quote_id = quote.entity_id)';
    }

    /**
     * The quote customer's last visit, over every session and device.
     *
     * @return string
     */
    private function getLastVisitExpression(): string
    {
        // Magento writes its online-customers log from PHP and quote.updated_at from SQL, so the two are
        // read off different clocks even with both hosts in sync, where the difference simply measures
        // zero. The visit is moved onto the database's reading: back when the application host runs ahead,
        // forward when it runs behind. It assumes one application host, which is what a store usually has;
        // with several, the one running the round lends its difference to visits written by the others.
        // Kept inside the clock's range here too: the range is a condition of the SQL, not only a policy
        // of the clock, and a value past it moves the date outside the column's own range.
        $offsetSeconds = max(
            -DatabaseClock::MAXIMUM_OFFSET_SECONDS,
            min(DatabaseClock::MAXIMUM_OFFSET_SECONDS, $this->databaseClock->getAppOffsetSeconds())
        );

        return '(SELECT MAX(customer_visit.last_visit_at) - INTERVAL '
            . $offsetSeconds . ' SECOND FROM '
            . $this->resourceConnection->getTableName('customer_visitor')
            . ' AS customer_visit WHERE customer_visit.customer_id = quote.customer_id)';
    }
}
