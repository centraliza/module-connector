<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\AbandonedCart;

use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaAuthException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Centraliza\Connector\Messaging\Exception\CentralizaRequestNotBuiltException;
use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Centraliza\Connector\Messaging\Api\AbandonedCartPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Centraliza\Connector\Messaging\Api\SendAbandonedCartsServiceInterface;
use Centraliza\Connector\Messaging\Model\AbandonedCart\AbandonmentPeriod;
use Centraliza\Connector\Messaging\Model\AbandonedCart\CartSender;
use Centraliza\Connector\Messaging\Model\AbandonedCart\RetryPolicy;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart\AbandonedQuoteQuery;
use Centraliza\Connector\Messaging\Model\ResourceModel\DatabaseClock;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SendAbandonedCartsService implements SendAbandonedCartsServiceInterface
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * Its request may have reached Centraliza, and an accepted cart must never be resent.
     */
    private const EXPIRED_RESERVATION_MESSAGE = 'Not resent: the send was interrupted and may have reached Centraliza';

    private const EXPIRED_CART_MESSAGE =
        'Expired before it could be sent: the cart had no activity for more than %d minutes';
    private const LOCAL_FAILURE_MESSAGE = 'Not sent: the cart could not be prepared (%s)';

    /**
     * @param AbandonedCartResource $abandonedCartResource
     * @param CartRepositoryInterface $cartRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param AbandonedCartPayloadBuilderInterface $payloadBuilder
     * @param CartSender $cartSender
     * @param RetryPolicy $retryPolicy
     * @param AbandonmentPeriod $abandonmentPeriod
     * @param DatabaseClock $databaseClock
     * @param LoggerInterface $logger
     * @param AbandonedCartConfigInterface $config
     * @param AbandonedQuoteQuery $abandonedQuoteQuery
     */
    public function __construct(
        private readonly AbandonedCartResource $abandonedCartResource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly AbandonedCartPayloadBuilderInterface $payloadBuilder,
        private readonly CartSender $cartSender,
        private readonly RetryPolicy $retryPolicy,
        private readonly AbandonmentPeriod $abandonmentPeriod,
        private readonly DatabaseClock $databaseClock,
        private readonly LoggerInterface $logger,
        private readonly AbandonedCartConfigInterface $config,
        private readonly AbandonedQuoteQuery $abandonedQuoteQuery
    ) {
    }

    /**
     * @param int[] $websiteIds
     * @return int requests made
     * @throws CentralizaApiException when Centraliza is down or unreachable
     */
    public function send(array $websiteIds): int
    {
        $requests = 0;
        $reservations = 0;
        $reservedIds = [];
        $websiteIdsToSend = array_values(array_map('intval', $websiteIds));

        // Websites of the key take turns, one row each, so one large backlog does not take the whole run.
        while (!empty($websiteIdsToSend)) {
            foreach ($websiteIdsToSend as $index => $websiteId) {
                if ($requests >= CentralizaClientInterface::MAX_REQUESTS_PER_KEY_RUN
                    || $reservations >= CentralizaClientInterface::MAX_RESERVATIONS_PER_KEY_RUN
                ) {
                    return $requests;
                }

                $row = $this->abandonedCartResource
                    ->reserveNext($this->databaseClock->getUtcDate(), $websiteId, $reservedIds);
                if (empty($row)) {
                    unset($websiteIdsToSend[$index]);
                    continue;
                }

                $reservations++;
                $reservedIds[] = (int) $row[AbandonedCartInterface::ENTITY_ID];

                $prepared = $this->prepareSend($row);
                if (empty($prepared)) {
                    continue;
                }

                try {
                    $this->cartSender->send($prepared['payload'], $websiteId);
                    $requests++;
                    $this->recordSuccess(
                        $row,
                        $prepared['quote_updated_at'],
                        $prepared['cart_signature'],
                        $prepared['last_activity_at']
                    );
                } catch (CentralizaRequestNotBuiltException $exception) {
                    // Only this cart's request is at fault, so the round goes on.
                    $this->recordRequestNotBuilt($row, $exception);
                } catch (CentralizaApiException $exception) {
                    if (!$exception->wasNeverSent()) {
                        $requests++;
                    }

                    // A clean refusal or a call that never left: the row goes back unchanged.
                    $givenBack = $exception->wasNeverSent()
                        || ($exception instanceof CentralizaAuthException && !$exception->isRequestPossiblySent());
                    if ($givenBack) {
                        $this->releaseReservation($row);
                    } else {
                        $this->recordFailure($row, $exception, $prepared);
                    }

                    // Centraliza down ends the whole run: the other keys would only wait out the same outage.
                    if ($exception->isUnavailable()) {
                        throw $exception;
                    }

                    // The other rows of the key would get the same answer; the Base pause or the 24 h limit ends them.
                    if ($givenBack) {
                        $this->logger->info('Centraliza abandoned cart key round ended; the row was given back', [
                            'website_id' => $websiteId,
                            'http_status' => $exception->getHttpStatus(),
                            'exception_class' => $exception::class,
                        ]);

                        return $requests;
                    }

                    if ($exception instanceof CentralizaRateLimitException
                        || $exception instanceof CentralizaAuthException
                    ) {
                        return $requests;
                    }
                }
            }
        }

        return $requests;
    }

    /**
     * @return int
     */
    public function releaseExpiredReservations(): int
    {
        $before = gmdate(
            'Y-m-d H:i:s',
            $this->databaseClock->getUtcTimestamp()
            - CentralizaClientInterface::RESERVATION_TIMEOUT_MINUTES * self::SECONDS_PER_MINUTE
        );

        $released = 0;
        foreach ($this->abandonedCartResource->findExpiredReservations($before) as $row) {
            try {
                $released += (int) $this->abandonedCartResource->markExpiredReservationFailed(
                    (int) $row[AbandonedCartInterface::ENTITY_ID],
                    (int) $row[AbandonedCartInterface::ATTEMPTS],
                    $before,
                    self::EXPIRED_RESERVATION_MESSAGE,
                    $this->databaseClock->getUtcDate()
                );
            } catch (Throwable $exception) {
                // One row that cannot be written must not block the others.
                $this->logger->error(
                    'Centraliza abandoned cart expired reservation could not be released',
                    [
                        'entity_id' => (int) $row[AbandonedCartInterface::ENTITY_ID],
                        'exception_class' => $exception::class,
                    ]
                );
            }
        }

        if ($released > 0) {
            $this->logger->warning('Centraliza abandoned carts failed from expired reservations', [
                'rows' => $released,
            ]);
        }

        return $released;
    }

    /**
     * Re-check the reserved row's cart; what to send, or null when the row was settled without a send.
     *
     * @param array $row
     * @return array{payload: array, quote_updated_at: string, cart_signature: string, last_activity_at: string}|null
     */
    private function prepareSend(array $row): ?array
    {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];
        $attempts = (int) $row[AbandonedCartInterface::ATTEMPTS];

        try {
            // Read before the quote, so a change made while the payload is built is newer than what is
            // recorded as sent and still earns a resend.
            $cartSignature = (string) $this->abandonedQuoteQuery->getCartSignature(
                (int) $row[AbandonedCartInterface::QUOTE_ID]
            );
            $quote = $this->loadFreshQuote((int) $row[AbandonedCartInterface::QUOTE_ID]);

            // Converted into an order, emptied, deleted or no longer a customer's: never sent.
            if (empty($quote)
                || !$quote->getIsActive()
                || empty($quote->getItemsCount())
                || $quote->getCustomerIsGuest()
            ) {
                $this->abandonedCartResource->deleteReservation($entityId, $attempts);

                return null;
            }

            $websiteId = (int) $row[AbandonedCartInterface::WEBSITE_ID];

            // The total of messages per cart lowered after the row was queued: the admin's limit wins over the
            // queue. reminder_number counts from 0 (the first send); 0 as the total means unlimited.
            $reminderNumber = (int) $row[AbandonedCartInterface::REMINDER_NUMBER];
            $maxNotifications = $this->config->getMaxNotifications($websiteId);
            if (!empty($maxNotifications) && $reminderNumber >= $maxNotifications) {
                $this->abandonedCartResource->deleteReservation($entityId, $attempts);

                return null;
            }

            // The customer came back (changed the cart or visited the store): the row waits as pending, without
            // an attempt, until abandoned again.
            $abandonMinutes = $this->config->getAbandonMinutes($websiteId);
            $lastActivityAt = (string) $this->abandonedQuoteQuery->getLastActivityAt((int) $quote->getId());
            if (empty($lastActivityAt)) {
                $lastActivityAt = (string) $quote->getUpdatedAt();
            }
            $abandonedAt = $this->abandonmentPeriod->getAbandonedAt($lastActivityAt, $abandonMinutes);
            if (!empty($abandonedAt)) {
                $this->abandonedCartResource->postponeReservation(
                    $entityId,
                    $attempts,
                    $abandonedAt,
                    (string) $quote->getUpdatedAt(),
                    $lastActivityAt
                );

                return null;
            }

            // The cart's own activity, the same the selection measured against the idle limit: measuring the two
            // on different dates would queue a cart here and then discard it on the way out.
            $cartActivityAt = (string) $this->abandonedQuoteQuery->getCartActivityAt((int) $quote->getId());
            if (empty($cartActivityAt)) {
                $cartActivityAt = (string) $quote->getUpdatedAt();
            }
            if ($this->abandonmentPeriod->isPastSendLimit($cartActivityAt, $websiteId)) {
                // Never sent: the row goes, so the cart can be selected again if the customer changes it; nothing
                // reached Centraliza. A row with an attempt stays Failed: that attempt may have reached Centraliza,
                // and deleting the row would let the cart be sent again.
                if (empty($attempts)) {
                    $this->abandonedCartResource->deleteReservation($entityId, $attempts);

                    return null;
                }

                $this->abandonedCartResource->markFailedWithoutAttempt(
                    $entityId,
                    $attempts,
                    sprintf(self::EXPIRED_CART_MESSAGE, $this->config->getSendLimitMinutes($websiteId))
                );

                return null;
            }

            $payload = $this->payloadBuilder->build(
                $quote,
                $reminderNumber,
                (string) $row[AbandonedCartInterface::CREATED_AT]
            );
            if (empty($payload)) {
                $this->abandonedCartResource->deleteReservation($entityId, $attempts);

                return null;
            }

            return [
                'payload' => $payload,
                'quote_updated_at' => (string) $quote->getUpdatedAt(),
                'cart_signature' => $cartSignature,
                'last_activity_at' => $lastActivityAt,
            ];
        } catch (Throwable $exception) {
            // Counted as an attempt, so a cart that always fails to prepare ends failed.
            $this->logger->error(
                'Centraliza abandoned cart could not be prepared',
                ['entity_id' => $entityId, 'exception_class' => $exception::class]
            );
            $this->recordLocalFailure($row, $exception);

            return null;
        }
    }

    /**
     * @param array $row
     * @param string $quoteUpdatedAt updated_at of the quote version the message carried
     * @param string $cartSignature signature of the content the message carried
     * @param string $lastActivityAt activity seen when the message was built
     * @return void
     */
    private function recordSuccess(
        array $row,
        string $quoteUpdatedAt,
        string $cartSignature,
        string $lastActivityAt
    ): void {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];
        $attempts = (int) $row[AbandonedCartInterface::ATTEMPTS];

        // A failed write is tried once more; a row already moved on is not.
        try {
            $marked = $this->abandonedCartResource->markSuccess(
                $entityId,
                $attempts,
                $this->databaseClock->getUtcDate(),
                $quoteUpdatedAt,
                $cartSignature,
                $lastActivityAt
            );
            if (!$marked) {
                $this->logOutcomeNotWritten($row);
            }

            return;
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Centraliza abandoned cart accepted but could not be recorded; trying once more',
                ['entity_id' => $entityId, 'exception_class' => $exception::class]
            );
        }

        try {
            $marked = $this->abandonedCartResource->markSuccess(
                $entityId,
                $attempts,
                $this->databaseClock->getUtcDate(),
                $quoteUpdatedAt,
                $cartSignature,
                $lastActivityAt
            );
            if (!$marked) {
                $this->logOutcomeNotWritten($row);
            }
        } catch (Throwable $exception) {
            $this->logUnrecordedSend($row, $exception);
        }
    }

    /**
     * @param array $row
     * @param CentralizaRequestNotBuiltException $exception
     * @return void
     */
    private function recordRequestNotBuilt(array $row, CentralizaRequestNotBuiltException $exception): void
    {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];

        try {
            $written = $this->abandonedCartResource->markFailedWithoutAttempt(
                $entityId,
                (int) $row[AbandonedCartInterface::ATTEMPTS],
                $exception->getMessage()
            );
        } catch (Throwable $writeException) {
            // Left in processing, the expired-reservation release ends it failed.
            $this->logger->error('Centraliza abandoned cart unbuilt request could not be recorded', [
                'entity_id' => $entityId,
                'exception_class' => $writeException::class,
            ]);

            return;
        }

        if (!$written) {
            $this->logOutcomeNotWritten($row);
        }
    }

    /**
     * @param array $row
     * @param CentralizaApiException $exception
     * @param array $prepared what prepareSend returned for this row
     * @return void
     */
    private function recordFailure(array $row, CentralizaApiException $exception, array $prepared): void
    {
        // An unreadable accepted answer: Centraliza took the cart, so it is recorded as sent, with the
        // version and content that went out, like any other success.
        if ($exception->wasAccepted()) {
            $this->recordSuccess(
                $row,
                $prepared['quote_updated_at'],
                $prepared['cart_signature'],
                $prepared['last_activity_at']
            );

            return;
        }

        // A failed write is tried once more; a row already moved on is not.
        try {
            $written = $this->writeFailure($row, $exception);
        } catch (Throwable $writeException) {
            $this->logger->warning(
                'Centraliza abandoned cart failure could not be recorded; trying once more',
                [
                    'entity_id' => (int) $row[AbandonedCartInterface::ENTITY_ID],
                    'exception_class' => $writeException::class,
                ]
            );

            try {
                $written = $this->writeFailure($row, $exception);
            } catch (Throwable $writeException) {
                $this->logUnrecordedSend($row, $writeException);

                return;
            }
        }

        if (!$written) {
            $this->logOutcomeNotWritten($row);
        }
    }

    /**
     * @param array $row
     * @param CentralizaApiException $exception
     * @return bool false when the row was no longer processing under this reservation
     */
    private function writeFailure(array $row, CentralizaApiException $exception): bool
    {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];
        $attempts = (int) $row[AbandonedCartInterface::ATTEMPTS];

        // A failure raised before any answer (code 0) records no status.
        $httpStatus = $exception->getHttpStatus();
        if (empty($httpStatus)) {
            $httpStatus = null;
        }

        // A clean 429 waits for Retry-After without an attempt; a possibly sent one keeps the resend-once rule.
        if ($exception instanceof CentralizaRateLimitException && !$exception->isRequestPossiblySent()) {
            return $this->abandonedCartResource->markRetry(
                $entityId,
                $attempts,
                $this->retryPolicy->getRateLimitRetryAt(
                    $attempts,
                    (int) $row[AbandonedCartInterface::WEBSITE_ID],
                    (int) $exception->getRetryAfterSeconds()
                ),
                false,
                $httpStatus,
                $exception->getMessage(),
                $this->databaseClock->getUtcDate()
            );
        }

        // A refused key gets here only when its request may have arrived, so it is resent once at most.
        if ($exception instanceof CentralizaAuthException) {
            return $this->recordTemporaryFailure($row, $httpStatus, $exception->getMessage(), true);
        }

        // A request Centraliza rejected (4xx) never succeeds by repeating it.
        if (!$exception->isRetryable()) {
            return $this->abandonedCartResource->markFailed(
                $entityId,
                $attempts,
                $httpStatus,
                $exception->getMessage(),
                $this->databaseClock->getUtcDate()
            );
        }

        return $this->recordTemporaryFailure(
            $row,
            $httpStatus,
            $exception->getMessage(),
            $exception->isRequestPossiblySent()
        );
    }

    /**
     * Resend later when the policy allows it, fail for good otherwise; the attempt counts.
     *
     * @param array $row
     * @param int|null $httpStatus
     * @param string $error
     * @param bool $requestPossiblySent
     * @return bool false when the row was no longer processing under this reservation
     */
    private function recordTemporaryFailure(
        array $row,
        ?int $httpStatus,
        string $error,
        bool $requestPossiblySent
    ): bool {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];
        $attempts = (int) $row[AbandonedCartInterface::ATTEMPTS];
        $retryAt = $this->retryPolicy->getRetryAt(
            $attempts,
            (int) $row[AbandonedCartInterface::WEBSITE_ID],
            $requestPossiblySent
        );

        if (empty($retryAt)) {
            return $this->abandonedCartResource->markFailed(
                $entityId,
                $attempts,
                $httpStatus,
                $error,
                $this->databaseClock->getUtcDate()
            );
        }

        return $this->abandonedCartResource->markRetry(
            $entityId,
            $attempts,
            $retryAt,
            true,
            $httpStatus,
            $error,
            $this->databaseClock->getUtcDate()
        );
    }

    /**
     * @param array $row
     * @param Throwable $exception
     * @return void
     */
    private function recordLocalFailure(array $row, Throwable $exception): void
    {
        try {
            $this->recordTemporaryFailure($row, null, sprintf(self::LOCAL_FAILURE_MESSAGE, $exception::class), false);
        } catch (Throwable $writeException) {
            // Nothing was sent, so giving the row back as it was is safe.
            $this->logger->error(
                'Centraliza abandoned cart local failure could not be recorded',
                [
                    'entity_id' => (int) $row[AbandonedCartInterface::ENTITY_ID],
                    'exception_class' => $writeException::class,
                ]
            );
            $this->releaseReservation($row);
        }
    }

    /**
     * Give a reserved row back unchanged when nothing was sent or the key was refused.
     *
     * @param array $row
     * @return void
     */
    private function releaseReservation(array $row): void
    {
        $entityId = (int) $row[AbandonedCartInterface::ENTITY_ID];
        $attempts = (int) $row[AbandonedCartInterface::ATTEMPTS];
        $reservedFrom = (string) $row['reserved_from'];
        $nextAttemptAt = $row[AbandonedCartInterface::NEXT_ATTEMPT_AT];

        // Tried once more: left in processing, a cart never accepted would expire failed.
        try {
            $released = $this->abandonedCartResource->releaseReservation(
                $entityId,
                $attempts,
                $reservedFrom,
                $nextAttemptAt
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Centraliza abandoned cart release could not be recorded; trying once more',
                ['entity_id' => $entityId, 'exception_class' => $exception::class]
            );

            try {
                $released = $this->abandonedCartResource->releaseReservation(
                    $entityId,
                    $attempts,
                    $reservedFrom,
                    $nextAttemptAt
                );
            } catch (Throwable $exception) {
                $this->logger->critical('Centraliza abandoned cart release could not be recorded', [
                    'entity_id' => $entityId,
                    'exception_class' => $exception::class,
                ]);

                return;
            }
        }

        if (!$released) {
            $this->logger->warning(
                'Centraliza abandoned cart row was no longer processing; its release was not recorded',
                ['entity_id' => $entityId]
            );
        }
    }

    /**
     * Log a write after the request that found the row already moved on by another process.
     *
     * @param array $row
     * @return void
     */
    private function logOutcomeNotWritten(array $row): void
    {
        $this->logger->warning(
            'Centraliza abandoned cart row was no longer processing; its outcome was not recorded',
            ['entity_id' => (int) $row[AbandonedCartInterface::ENTITY_ID]]
        );
    }

    /**
     * @param array $row
     * @param Throwable $exception
     * @return void
     */
    private function logUnrecordedSend(array $row, Throwable $exception): void
    {
        // Left in processing: the expired-reservation release ends it failed, never resent.
        $this->logger->critical('Centraliza abandoned cart sent but its outcome was not recorded', [
            'entity_id' => (int) $row[AbandonedCartInterface::ENTITY_ID],
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * A fresh read of the cart, never the repository's cached copy from the selection.
     *
     * @param int $quoteId
     * @return CartInterface|null
     */
    private function loadFreshQuote(int $quoteId): ?CartInterface
    {
        // No is_active filter: a converted cart must be seen so its row is deleted.
        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('entity_id', $quoteId)
            ->create();
        $quotes = $this->cartRepository->getList($searchCriteria)->getItems();
        if (empty($quotes)) {
            return null;
        }

        return reset($quotes);
    }
}
