<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\AbandonedCart;

use Centraliza\Connector\Messaging\Api\StoreScopeInterface;
use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Centraliza\Connector\Messaging\Api\AbandonedCartPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Centraliza\Connector\Messaging\Api\SelectAbandonedCartsServiceInterface;
use Centraliza\Connector\Messaging\Model\AbandonedCart\AbandonmentPeriod;
use Centraliza\Connector\Messaging\Model\AbandonedCart\Payload\CustomerContactResolver;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart\AbandonedQuoteQuery;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Throwable;

class SelectAbandonedCartsService implements SelectAbandonedCartsServiceInterface
{
    /**
     * Technical batch size, not a business cap: quotes per query and per getList() page.
     */
    private const PAGE_SIZE = 100;

    /**
     * @param StoreScopeInterface $storeScope
     * @param AbandonmentPeriod $abandonmentPeriod
     * @param AbandonedQuoteQuery $abandonedQuoteQuery
     * @param CartRepositoryInterface $cartRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param CustomerContactResolver $customerContactResolver
     * @param AbandonedCartResource $abandonedCartResource
     * @param LoggerInterface $logger
     * @param LoggerInterface $debugLogger written only while the Base Debug Log is on
     * @param AbandonedCartConfigInterface $config
     */
    public function __construct(
        private readonly StoreScopeInterface $storeScope,
        private readonly AbandonmentPeriod $abandonmentPeriod,
        private readonly AbandonedQuoteQuery $abandonedQuoteQuery,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly CustomerContactResolver $customerContactResolver,
        private readonly AbandonedCartResource $abandonedCartResource,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $debugLogger,
        private readonly AbandonedCartConfigInterface $config
    ) {
    }

    /**
     * @param int $websiteId
     * @return int
     */
    public function execute(int $websiteId): int
    {
        if (!$this->storeScope->websiteExists($websiteId)) {
            $this->logger->warning(
                'Centraliza abandoned cart selection skipped: unknown website',
                ['website_id' => $websiteId]
            );

            return 0;
        }

        $period = $this->abandonmentPeriod->get($websiteId);
        if (empty($period)) {
            return 0;
        }

        // Only enabled store views: a disabled one cannot be opened, so its carts cannot be recovered.
        $storeIds = $this->storeScope->getActiveStoreIds($websiteId);
        $created = 0;
        foreach ($storeIds as $storeId) {
            $created += $this->selectForStore($storeId, $websiteId, $period);
        }

        // One message per cart unless the store allows more: a resend follows the customer coming back, whether
        // they changed the cart or only opened it again.
        $maxNotifications = $this->config->getMaxNotifications($websiteId);
        if ($maxNotifications === 1) {
            return $created;
        }

        // Every change followed by one run without activity then sends a message, with no end: a test setting that
        // lets an account with any telephone get a message every few minutes. Logged on each run it is active.
        if ($maxNotifications === 0 && $this->config->getAbandonMinutes($websiteId) === 0) {
            $this->logger->warning(
                'Centraliza abandoned cart: abandonment time 0 with unlimited notifications',
                ['website_id' => $websiteId]
            );
        }

        foreach ($storeIds as $storeId) {
            $created += $this->selectResendsForStore($storeId, $websiteId, $period, $maxNotifications);
        }

        return $created;
    }

    /**
     * Record a new send for each cart whose customer came back after its last message and abandoned again.
     *
     * @param int $storeId
     * @param int $websiteId
     * @param array{from: string, to: string, activity_from: string} $period
     * @param int $maxNotifications messages per cart including the first; 0 means unlimited
     * @return int
     */
    private function selectResendsForStore(int $storeId, int $websiteId, array $period, int $maxNotifications): int
    {
        $foundQuotes = 0;
        $created = 0;
        $afterQuoteId = 0;

        do {
            $resends = $this->abandonedQuoteQuery->findResendQuoteIds(
                $storeId,
                $maxNotifications,
                $period['from'],
                $period['to'],
                $period['activity_from'],
                $afterQuoteId,
                self::PAGE_SIZE
            );
            if (empty($resends)) {
                break;
            }

            $quoteIds = array_keys($resends);
            $foundQuotes += count($quoteIds);
            $afterQuoteId = max($quoteIds);

            $lastActivityByQuoteId = [];
            $reminderNumbers = [];
            foreach ($resends as $quoteId => $resend) {
                $lastActivityByQuoteId[$quoteId] = (string) $resend[AbandonedCartInterface::LAST_ACTIVITY_AT];
                $reminderNumbers[$quoteId] = (int) $resend[AbandonedCartInterface::REMINDER_NUMBER];
            }

            $rows = $this->buildPendingRows($quoteIds, $storeId, $websiteId, $lastActivityByQuoteId, $reminderNumbers);

            try {
                $created += $this->abandonedCartResource->insertPending($rows);
            } catch (Throwable $exception) {
                // These carts still have no new row, so the next run retries them.
                $this->logger->error(
                    'Centraliza abandoned cart resends could not record a page',
                    [
                        'store_id' => $storeId,
                        'cart_ids' => array_column($rows, AbandonedCartInterface::QUOTE_ID),
                        'exception_class' => $exception::class,
                    ]
                );

                break;
            }
        } while (count($resends) === self::PAGE_SIZE);

        if ($foundQuotes > 0) {
            $this->debugLogger->info(
                'Centraliza abandoned cart resend selection',
                ['store_id' => $storeId, 'found' => $foundQuotes, 'created' => $created]
            );
        }

        return $created;
    }

    /**
     * @param int $storeId
     * @param int $websiteId
     * @param array{from: string, to: string, activity_from: string} $period
     * @return int
     */
    private function selectForStore(int $storeId, int $websiteId, array $period): int
    {
        $foundQuotes = 0;
        $discarded = 0;
        $created = 0;
        $afterQuoteId = 0;

        do {
            $lastActivityByQuoteId = $this->abandonedQuoteQuery->findQuoteIds(
                $storeId,
                $period['from'],
                $period['to'],
                $period['activity_from'],
                $afterQuoteId,
                self::PAGE_SIZE
            );
            if (empty($lastActivityByQuoteId)) {
                break;
            }

            $quoteIds = array_keys($lastActivityByQuoteId);
            $foundQuotes += count($quoteIds);
            $afterQuoteId = max($quoteIds);
            $rows = $this->buildPendingRows($quoteIds, $storeId, $websiteId, $lastActivityByQuoteId);
            $discarded += count($quoteIds) - count($rows);

            try {
                $created += $this->abandonedCartResource->insertPending($rows);
            } catch (Throwable $exception) {
                // The other store views go on; these carts still have no row, so the next run retries them.
                $this->logger->error(
                    'Centraliza abandoned cart selection could not record a page',
                    [
                        'store_id' => $storeId,
                        'cart_ids' => array_column($rows, AbandonedCartInterface::QUOTE_ID),
                        'exception_class' => $exception::class,
                    ]
                );

                break;
            }
        } while (count($lastActivityByQuoteId) === self::PAGE_SIZE);

        // Routine trace: one line per store view on every run, so it follows the Debug Log switch.
        if ($foundQuotes > 0) {
            $this->debugLogger->info(
                'Centraliza abandoned cart selection',
                [
                    'store_id' => $storeId,
                    'found' => $foundQuotes,
                    'discarded' => $discarded,
                    'created' => $created,
                ]
            );
        }

        return $created;
    }

    /**
     * @param int[] $quoteIds
     * @param int $storeId
     * @param int $websiteId
     * @param array<int, string> $lastActivityByQuoteId quote id => last activity the selection was made on
     * @param array<int, int> $reminderNumbers quote id => reminder number; a quote missing here is a first send
     * @return array
     */
    private function buildPendingRows(
        array $quoteIds,
        int $storeId,
        int $websiteId,
        array $lastActivityByQuoteId,
        array $reminderNumbers = []
    ): array {
        $rows = [];
        foreach ($this->loadActiveQuotes($quoteIds) as $quote) {
            $quoteId = (int) $quote->getId();

            if (!$quote instanceof Quote) {
                $this->logSkipped($quoteId, AbandonedCartPayloadBuilderInterface::REASON_UNSUPPORTED_CART);
                continue;
            }

            // Only whether the customer can be reached: the payload is built at send time, since
            // building it creates the cart's masked id, never wanted for a cart that is not sent.
            try {
                $contact = $this->customerContactResolver->resolve($quote);
            } catch (Throwable $exception) {
                // One unreadable cart must not cost the rest of the page or the other store views.
                $this->logger->error(
                    'Centraliza abandoned cart could not be checked',
                    ['cart_id' => $quoteId, 'exception_class' => $exception::class]
                );

                continue;
            }

            if (empty($contact)) {
                $this->logSkipped($quoteId, AbandonedCartPayloadBuilderInterface::REASON_MISSING_CONTACT);
                continue;
            }

            $rows[] = [
                AbandonedCartInterface::QUOTE_ID => $quoteId,
                AbandonedCartInterface::STORE_ID => $storeId,
                AbandonedCartInterface::WEBSITE_ID => $websiteId,
                AbandonedCartInterface::QUOTE_UPDATED_AT => (string) $quote->getUpdatedAt(),
                // The date the selection was made on, not one read again here: a cart whose customer came back
                // between the query and this loop would otherwise record an activity that never made it abandoned.
                AbandonedCartInterface::LAST_ACTIVITY_AT => $lastActivityByQuoteId[$quoteId] ?? null,
                AbandonedCartInterface::REMINDER_NUMBER => $reminderNumbers[$quoteId] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @param int $quoteId
     * @param string $reason
     * @return void
     */
    private function logSkipped(int $quoteId, string $reason): void
    {
        // Only the cart id and the reason: the customer's name and telephone never reach the log.
        $this->debugLogger->info('Centraliza abandoned cart skipped', ['cart_id' => $quoteId, 'reason' => $reason]);
    }

    /**
     * Load the page in one query, repeating the active and non-empty conditions for carts changed since.
     *
     * @param int[] $quoteIds
     * @return CartInterface[]
     */
    private function loadActiveQuotes(array $quoteIds): array
    {
        // getList(), not get(), which caches every quote for the whole process. Never saved: a save
        // would move updated_at and pull the cart out of the range.
        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('entity_id', $quoteIds, 'in')
            ->addFilter('is_active', 1)
            ->addFilter('items_count', 0, 'gt')
            ->create();

        try {
            return $this->cartRepository->getList($searchCriteria)->getItems();
        } catch (Throwable $exception) {
            $this->logger->error(
                'Centraliza abandoned cart selection could not load a page',
                ['cart_ids' => $quoteIds, 'exception_class' => $exception::class]
            );

            return [];
        }
    }
}
