<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\CartRedirect;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Throwable;

/**
 * Link ids of customer carts, kept out of quote_id_mask: there any anonymous route that skips the owner check opens the cart.
 */
class CartLink
{
    public const TABLE = 'centraliza_sales_cart_link';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Quote the link id belongs to, or 0.
     *
     * @param string $maskedId
     * @return int
     */
    public function getQuoteId(string $maskedId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['quote_id'])
            ->where('masked_id = ?', $maskedId);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Oldest link id of the quote, or an empty string.
     *
     * @param int $quoteId
     * @return string
     */
    public function getMaskedId(int $quoteId): string
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['masked_id'])
            ->where('quote_id = ?', $quoteId)
            ->order(['created_at ASC', 'masked_id ASC'])
            ->limit(1);

        return (string) $connection->fetchOne($select);
    }

    /**
     * Record the link id for the quote; an id that already exists keeps its quote.
     *
     * @param int $quoteId
     * @param string $maskedId
     * @return void
     */
    public function add(int $quoteId, string $maskedId): void
    {
        try {
            $this->resourceConnection->getConnection()->insert(
                $this->resourceConnection->getTableName(self::TABLE),
                ['masked_id' => $maskedId, 'quote_id' => $quoteId]
            );
        } catch (DuplicateException) {
            // Only a merge moves an id to another cart (moveToQuote): an id that moved would open that cart.
        }
    }

    /**
     * Keep the link id of a customer's cart here and take it out of quote_id_mask.
     *
     * @param int $quoteId
     * @param string $maskedId
     * @return void
     */
    public function keep(int $quoteId, string $maskedId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->add($quoteId, $maskedId);
            if ($this->getQuoteId($maskedId) === $quoteId) {
                $connection->delete(
                    $this->resourceConnection->getTableName('quote_id_mask'),
                    ['masked_id = ?' => $maskedId]
                );
            }

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * Point every link id of one quote to another.
     *
     * @param int $fromQuoteId
     * @param int $toQuoteId
     * @return void
     */
    public function moveToQuote(int $fromQuoteId, int $toQuoteId): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['quote_id' => $toQuoteId],
            ['quote_id = ?' => $fromQuoteId]
        );
    }
}
