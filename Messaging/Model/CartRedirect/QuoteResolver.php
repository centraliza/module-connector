<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\CartRedirect;

use Centraliza\Connector\Messaging\Model\ResourceModel\CartRedirect\CartLink;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;

class QuoteResolver
{
    /**
     * Magento generates a masked quote id as 32 letters and digits, so anything else, a numeric
     * quote id included, is rejected before reaching the database.
     */
    private const MASKED_ID_PATTERN = '/^[a-zA-Z0-9]{32}\z/';

    /**
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param QuoteIdMaskResource $quoteIdMaskResource
     * @param CartRepositoryInterface $cartRepository
     * @param CartLink $cartLink
     */
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly QuoteIdMaskResource $quoteIdMaskResource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartLink $cartLink
    ) {
    }

    /**
     * Quote id behind the masked id, read without loading the quote.
     *
     * @param string $maskedId
     * @return int
     * @throws LocalizedException
     */
    public function getQuoteId(string $maskedId): int
    {
        if (!preg_match(self::MASKED_ID_PATTERN, $maskedId)) {
            throw new LocalizedException(__('invalid_cart_id'));
        }

        // A customer cart's link id is read first: out of quote_id_mask, anyone can register the same value there
        // for a cart of their own (GraphQL createGuestCart with cart_uid), and that cart must never take the link.
        $quoteId = $this->cartLink->getQuoteId($maskedId);
        if (empty($quoteId)) {
            $quoteIdMask = $this->quoteIdMaskFactory->create();
            $this->quoteIdMaskResource->load($quoteIdMask, $maskedId, 'masked_id');
            $quoteId = (int) $quoteIdMask->getQuoteId();
        }

        if (empty($quoteId)) {
            throw new LocalizedException(__('unknown_quote'));
        }

        return $quoteId;
    }

    /**
     * Active quote with items on the website.
     *
     * @param int $quoteId
     * @param int $websiteId
     * @return Quote
     * @throws LocalizedException
     */
    public function get(int $quoteId, int $websiteId): Quote
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('unknown_quote'));
        }

        if (!$quote instanceof Quote) {
            throw new LocalizedException(__('unknown_quote'));
        }

        // An inactive quote was ordered or replaced by a later link, and is never reopened.
        if (!$quote->getIsActive()) {
            throw new LocalizedException(__('inactive_quote'));
        }

        if ((int) $quote->getStore()->getWebsiteId() !== $websiteId) {
            throw new LocalizedException(__('wrong_website'));
        }

        if (empty($quote->getAllVisibleItems())) {
            throw new LocalizedException(__('empty_quote'));
        }

        return $quote;
    }
}
