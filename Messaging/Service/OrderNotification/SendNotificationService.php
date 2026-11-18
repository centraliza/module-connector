<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\OrderNotification;

use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaAuthException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Centraliza\Connector\Messaging\Exception\CentralizaRequestNotBuiltException;
use Centraliza\Connector\Messaging\Api\OrderCreatedPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\OrderNotificationConfigInterface;
use Centraliza\Connector\Messaging\Api\OrderPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\SendNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime as DateTimeFormat;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SendNotificationService implements SendNotificationServiceInterface
{
    /**
     * Fixed by Centraliza: a configurable path could send the key and the customer's data elsewhere.
     */
    public const SEND_PATH = 'admin/events';

    private const REFUSED_KEY_MESSAGE = 'Postponed: Centraliza refused the key of this website in this round';

    private const RATE_LIMITED_MESSAGE = 'Postponed: Centraliza limited the requests of this key in this round';

    private const EXPIRED_MESSAGE = 'Expired before it could be sent';

    private const EXPIRED_WHILE_SKIPPED_MESSAGE = 'Expired while its scope could not send';

    private const ORDER_NOT_LOADED_MESSAGE = 'Order could not be loaded';

    private const MISSING_STATUS_MESSAGE = 'Not sent: the order status is missing';
    private const ORDER_CREATED_OFF_MESSAGE = 'Not sent: Send Order Created and Origin Cart is off';

    private const UNEXPECTED_ERROR_MESSAGE = 'Unexpected error while sending';

    private const EMPTY_PAYLOAD_MESSAGE = 'The payload builder returned an empty payload';

    private const ATTEMPT_LIMIT_REACHED_MESSAGE = 'Failed: the attempt limit was already reached';

    private const UNKNOWN_OUTCOME_PREFIX = 'Outcome unknown: ';

    private const UNEXPECTED_REQUEST_ERROR_MESSAGE =
        self::UNKNOWN_OUTCOME_PREFIX . 'unexpected error during the request';

    /**
     * The id is read from data.id (request() already unwraps data); confirmed for the old send route, not yet
     * for admin/events. Without it the notification is accepted with no external id.
     */
    private const EXTERNAL_ID_FIELD = 'id';

    private const SECONDS_PER_MINUTE = 60;

    /**
     * @param NotificationResource $notificationResource
     * @param CentralizaClientInterface $client
     * @param OrderNotificationConfigInterface $config
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderPayloadBuilderInterface $payloadBuilder
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param LoggerInterface $debugLogger written only while the Base Debug Log is on
     * @param OrderCreatedPayloadBuilderInterface $orderCreatedPayloadBuilder
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly CentralizaClientInterface $client,
        private readonly OrderNotificationConfigInterface $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderPayloadBuilderInterface $payloadBuilder,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $debugLogger,
        private readonly OrderCreatedPayloadBuilderInterface $orderCreatedPayloadBuilder
    ) {
    }

    /**
     * @param int[] $websiteIds
     * @return int
     * @throws CentralizaApiException
     */
    public function sendReadyRows(array $websiteIds): int
    {
        $requests = 0;
        $reservations = 0;
        $websiteIdsToSend = array_values($websiteIds);

        // Websites sharing a key share its quota, so they take turns, one row each.
        while (!empty($websiteIdsToSend)) {
            foreach ($websiteIdsToSend as $index => $websiteId) {
                // Only requests made count: a row settled without one costs no quota.
                if ($requests >= CentralizaClientInterface::MAX_REQUESTS_PER_KEY_RUN) {
                    return $requests;
                }

                if ($reservations >= CentralizaClientInterface::MAX_RESERVATIONS_PER_KEY_RUN) {
                    $this->logger->info(
                        'Centraliza notification key turn ended: reservation limit reached',
                        ['reservations' => $reservations, 'website_ids' => $websiteIdsToSend]
                    );

                    return $requests;
                }

                $row = $this->notificationResource->reserveNext(
                    $this->dateTime->gmtDate(DateTimeFormat::DATETIME_PHP_FORMAT),
                    $websiteId
                );
                if (empty($row)) {
                    unset($websiteIdsToSend[$index]);

                    continue;
                }

                $reservations++;
                $payload = $this->preparePayload($row);
                if (empty($payload)) {
                    continue;
                }

                try {
                    $response = $this->client->request('POST', self::SEND_PATH, ['json' => $payload], $websiteId);
                } catch (CentralizaApiException $exception) {
                    if ($exception->wasAccepted()) {
                        // A 2xx is acceptance whatever its body: resending could duplicate the WhatsApp.
                        $this->logger->warning(
                            'Centraliza accepted the notification but its answer could not be read; '
                                . 'recorded without the id',
                            ['increment_id' => $row['increment_id'], 'notification_id' => (int) $row['entity_id']]
                        );
                        $requests++;
                        $this->recordDelivery($row, null);

                        continue;
                    }

                    $this->recordRequestFailureSafely($row, $exception);

                    if ($exception instanceof CentralizaRequestNotBuiltException) {
                        continue;
                    }

                    if (!$exception->wasNeverSent()) {
                        $requests++;
                    }

                    if ($exception->isUnavailable()) {
                        throw $exception;
                    }

                    // Every other row of the key would be refused the same way, so its turn ends here.
                    if ($exception->wasNeverSent()) {
                        $this->logger->info(
                            'Centraliza notification key turn ended: the request was not sent',
                            ['website_id' => $websiteId, 'exception_class' => $exception::class]
                        );

                        return $requests;
                    }

                    if ($exception instanceof CentralizaRateLimitException
                        || $exception instanceof CentralizaAuthException
                    ) {
                        $this->debugLogger->info(
                            'Centraliza notification key left out of the rest of this round',
                            ['website_id' => $websiteId, 'code' => $exception->getHttpStatus()]
                        );

                        return $requests;
                    }

                    continue;
                } catch (Throwable $exception) {
                    // Not from the Base, so it may have been raised after the request left: counted, ends unknown.
                    $requests++;
                    $this->recordRequestFailureSafely($row, $exception);

                    continue;
                }

                $requests++;
                $this->recordDelivery($row, $response);
            }
        }

        return $requests;
    }

    /**
     * @param int $websiteId
     * @return int
     */
    public function failRowsPastSendWindow(int $websiteId): int
    {
        // A skipped website never has a row reserved, so preparePayload() never expires its rows.
        return $this->notificationResource->failWaitingCreatedBefore(
            $websiteId,
            $this->getExpiryCutoff($websiteId),
            self::EXPIRED_WHILE_SKIPPED_MESSAGE
        );
    }

    /**
     * @param string $createdAt
     * @param int $websiteId
     * @return bool
     */
    public function isPastSendWindow(string $createdAt, int $websiteId): bool
    {
        return $createdAt < $this->getExpiryCutoff($websiteId);
    }

    /**
     * Check the reserved row and build its payload; null when the row was settled without a request.
     *
     * @param array $row a row returned by NotificationResource::reserveNext()
     * @return array|null
     */
    private function preparePayload(array $row): ?array
    {
        $websiteId = (int) $row['website_id'];

        // Not sent late on purpose: today's message about yesterday's status confuses the customer.
        if ($this->isPastSendWindow((string) $row['created_at'], $websiteId)) {
            $this->recordFailureBeforeRequest($row, self::EXPIRED_MESSAGE, false);

            return null;
        }

        // The limit may have been lowered, or resend switched off, after the row was scheduled.
        if ((int) $row['attempts'] >= $this->config->getMaxSends($websiteId)) {
            $this->recordFailureBeforeRequest($row, self::ATTEMPT_LIMIT_REACHED_MESSAGE, false);

            return null;
        }

        // Centraliza contract: the status is part of the event's idempotency key, so an event without it
        // is never sent.
        if (empty(trim((string) $row['to_status']))) {
            $this->recordFailureBeforeRequest($row, self::MISSING_STATUS_MESSAGE, false);

            return null;
        }

        // The setting is turned off when Centraliza refuses the event: one queued before that is not sent either.
        if ($row['event_type'] === Notification::EVENT_ORDER_CREATED
            && !$this->config->isOrderCreatedEnabled($websiteId)
        ) {
            $this->recordFailureBeforeRequest($row, self::ORDER_CREATED_OFF_MESSAGE, false);

            return null;
        }

        try {
            try {
                $order = $this->orderRepository->get((int) $row['order_id']);
            } catch (NoSuchEntityException | InputException) {
                $this->recordFailureBeforeRequest($row, self::ORDER_NOT_LOADED_MESSAGE, false);

                return null;
            }

            $payload = $this->buildPayload($order, $row);
        } catch (Throwable $exception) {
            // Retried within the attempt limit; logged by class only, the message may carry payload data.
            $this->logger->error('Centraliza notification send failed with an unexpected error', [
                'increment_id' => $row['increment_id'],
                'notification_id' => (int) $row['entity_id'],
                'exception_class' => $exception::class,
            ]);
            $this->recordFailureBeforeRequest($row, self::UNEXPECTED_ERROR_MESSAGE, true);

            return null;
        }

        // A builder plugged in through the preference may return an empty body.
        if (empty($payload)) {
            $this->logger->error('Centraliza notification payload builder returned an empty payload', [
                'increment_id' => $row['increment_id'],
                'notification_id' => (int) $row['entity_id'],
            ]);
            $this->recordFailureBeforeRequest($row, self::EMPTY_PAYLOAD_MESSAGE, true);

            return null;
        }

        return $payload;
    }

    /**
     * Body of the row's event: the order created event or the status change.
     *
     * @param OrderInterface $order
     * @param array $row
     * @return array
     */
    private function buildPayload(OrderInterface $order, array $row): array
    {
        if ($row['event_type'] === Notification::EVENT_ORDER_CREATED) {
            return $this->orderCreatedPayloadBuilder->build($order, (string) $row['to_status']);
        }

        return $this->payloadBuilder->build($order, (string) $row['to_status']);
    }

    /**
     * Record a row that failed before its request: failed at once, or retried within the attempt limit.
     *
     * @param array $row
     * @param string $message
     * @param bool $isRetryable true for a local error that may clear; it fails on the last allowed send
     * @return void
     */
    private function recordFailureBeforeRequest(array $row, string $message, bool $isRetryable): void
    {
        $id = (int) $row['entity_id'];
        $reservedAttempts = (int) $row['attempts'];

        try {
            if (!$isRetryable) {
                $written = $this->notificationResource->markFailed($id, $reservedAttempts, null, $message, false);
            } elseif ($this->canRetry($row)) {
                $written = $this->notificationResource->markRetry(
                    $id,
                    $reservedAttempts,
                    null,
                    $message,
                    $this->minutesFromNow(Notification::RETRY_DELAY_MINUTES)
                );
            } else {
                $written = $this->notificationResource->markFailed($id, $reservedAttempts, null, $message, true);
            }
        } catch (Throwable $writeException) {
            $this->logWriteFailure($id, $writeException);

            return;
        }

        if (!$written) {
            $this->logRowMovedOn($id);
        }
    }

    /**
     * Record a failed request on the reserved row, logging instead of raising a failed write.
     *
     * @param array $row
     * @param Throwable $failure what the request raised
     * @return void
     */
    private function recordRequestFailureSafely(array $row, Throwable $failure): void
    {
        $id = (int) $row['entity_id'];

        // Not raised: the key's turn follows Centraliza's answer; a row left in sending expires to unknown.
        try {
            $written = $this->recordRequestFailure($row, $failure);
        } catch (Throwable $writeException) {
            $this->logWriteFailure($id, $writeException);

            return;
        }

        if (!$written) {
            $this->logRowMovedOn($id);
        }
    }

    /**
     * Release, postpone, retry, fail or mark unknown the reserved row, by the kind of failure.
     *
     * @param array $row
     * @param Throwable $failure
     * @return bool false when the row was no longer in sending under this reservation
     */
    private function recordRequestFailure(array $row, Throwable $failure): bool
    {
        $id = (int) $row['entity_id'];
        $reservedAttempts = (int) $row['attempts'];

        if (!$failure instanceof CentralizaApiException) {
            // Logged by class only: the message may carry payload data.
            $this->logger->error('Centraliza notification request failed with an unexpected error; outcome unknown', [
                'increment_id' => $row['increment_id'],
                'notification_id' => $id,
                'exception_class' => $failure::class,
            ]);

            return $this->notificationResource->markUnknown(
                $id,
                $reservedAttempts,
                null,
                self::UNEXPECTED_REQUEST_ERROR_MESSAGE
            );
        }

        $httpStatus = $failure->getHttpStatus();

        // Fails the same way on every resend; nothing was sent, so no attempt is spent.
        if ($failure instanceof CentralizaRequestNotBuiltException) {
            $this->logger->warning(
                'Centraliza notification could not be sent: the request was not built',
                ['increment_id' => $row['increment_id'], 'notification_id' => $id]
            );

            return $this->notificationResource->markFailed(
                $id,
                $reservedAttempts,
                $httpStatus,
                $failure->getMessage(),
                false
            );
        }

        // Nothing was sent: the row goes back exactly as it was, and the send window ends it.
        if ($failure->wasNeverSent()) {
            try {
                return $this->releaseReservation($row);
            } catch (Throwable $exception) {
                // Retried once: a row that was never sent must not end unknown because one write failed.
                $this->logger->warning(
                    'Centraliza notification release could not be recorded; trying once more',
                    ['notification_id' => $id, 'exception_class' => $exception::class]
                );

                return $this->releaseReservation($row);
            }
        }

        // Checked before the code: deduplication on idempotency_key is not confirmed by Centraliza, so a request
        // that may have arrived is never resent.
        if ($failure->isRequestPossiblySent()) {
            $this->logger->warning(
                'Centraliza notification outcome unknown: the request may have been sent',
                ['increment_id' => $row['increment_id'], 'notification_id' => $id]
            );

            return $this->notificationResource->markUnknown(
                $id,
                $reservedAttempts,
                $httpStatus,
                self::UNKNOWN_OUTCOME_PREFIX . $failure->getMessage()
            );
        }

        // A refused key may be replaced and a 429 clears: no attempt is spent; the send window ends the row.
        if ($failure instanceof CentralizaAuthException || $failure instanceof CentralizaRateLimitException) {
            $message = self::REFUSED_KEY_MESSAGE;
            if ($failure instanceof CentralizaRateLimitException) {
                $message = self::RATE_LIMITED_MESSAGE;
            }

            $this->logger->warning(
                'Centraliza notification postponed: the key cannot send in this round',
                ['increment_id' => $row['increment_id'], 'notification_id' => $id, 'code' => $httpStatus]
            );

            return $this->notificationResource->postponeReservation(
                $id,
                $reservedAttempts,
                (string) $row['reserved_from'],
                $this->minutesFromNow(Notification::RETRY_DELAY_MINUTES),
                $httpStatus,
                $message
            );
        }

        $canRetry = in_array($httpStatus, Notification::RETRYABLE_CODES, true) && $this->canRetry($row);

        // Never log the payload: it carries the customer's name and phone number.
        $this->logger->warning(
            'Centraliza notification send failed',
            ['increment_id' => $row['increment_id'], 'code' => $httpStatus, 'will_retry' => $canRetry]
        );

        if ($canRetry) {
            return $this->notificationResource->markRetry(
                $id,
                $reservedAttempts,
                $httpStatus,
                $failure->getMessage(),
                $this->minutesFromNow(Notification::RETRY_DELAY_MINUTES)
            );
        }

        return $this->notificationResource->markFailed(
            $id,
            $reservedAttempts,
            $httpStatus,
            $failure->getMessage(),
            true
        );
    }

    /**
     * @param array $row
     * @return bool false when the row was no longer in sending under this reservation
     */
    private function releaseReservation(array $row): bool
    {
        return $this->notificationResource->releaseReservation(
            (int) $row['entity_id'],
            (int) $row['attempts'],
            (string) $row['reserved_from'],
            $row['next_attempt_at']
        );
    }

    /**
     * Record a 2xx as delivered without ever letting the row be sent again.
     *
     * @param array $row
     * @param array|null $response the unwrapped answer; null when the Base could not read it
     * @return void
     */
    private function recordDelivery(array $row, ?array $response): void
    {
        // Accepted, so never sent again: a failed write is retried once without the external id.
        $id = (int) $row['entity_id'];
        $reservedAttempts = (int) $row['attempts'];
        $context = ['increment_id' => $row['increment_id'], 'notification_id' => $id];

        try {
            // Compared with null, not empty(): a 2xx whose body is [] is read so the missing id is reported.
            $externalId = null;
            if ($response !== null) {
                $externalId = $this->readExternalId($response);
            }

            if (!$this->notificationResource->markDelivered($id, $reservedAttempts, $externalId)) {
                $this->logRowMovedOn($id);
            }

            return;
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Centraliza notification accepted but could not be recorded; retrying without the id',
                $context + ['exception_class' => $exception::class]
            );
        }

        try {
            if (!$this->notificationResource->markDelivered($id, $reservedAttempts, null)) {
                $this->logRowMovedOn($id);
            }
        } catch (Throwable $exception) {
            $this->logger->critical(
                'Centraliza notification accepted but could not be recorded as delivered',
                $context + ['exception_class' => $exception::class]
            );
        }
    }

    /**
     * @param int $id
     * @return void
     */
    private function logRowMovedOn(int $id): void
    {
        $this->logger->warning(
            'Centraliza notification row was no longer in sending; its outcome was not recorded',
            ['notification_id' => $id]
        );
    }

    /**
     * @param int $id
     * @param Throwable $writeException
     * @return void
     */
    private function logWriteFailure(int $id, Throwable $writeException): void
    {
        $this->logger->error('Centraliza notification outcome could not be recorded', [
            'notification_id' => $id,
            'exception_class' => $writeException::class,
        ]);
    }

    /**
     * Centraliza's id for the accepted notification, or null when missing or malformed.
     *
     * @param array $response unwrapped JSON:API data node
     * @return string|null
     */
    private function readExternalId(array $response): ?string
    {
        $id = $response[self::EXTERNAL_ID_FIELD] ?? null;

        if (!is_int($id) && !is_string($id)) {
            $this->logger->warning('Centraliza send response carried no notification id');

            return null;
        }

        $id = (string) $id;
        if (!preg_match(Notification::EXTERNAL_ID_PATTERN, $id)) {
            // Not logged: unvalidated input from the response body.
            $this->logger->warning('Centraliza send response carried a malformed notification id');

            return null;
        }

        return $id;
    }

    /**
     * Whether the row may be sent again after this send failed, under its website's settings.
     *
     * @param array $row
     * @return bool
     */
    private function canRetry(array $row): bool
    {
        // attempts counts the sends before this one, so the send reaching the limit is the last.
        return (int) $row['attempts'] + 1 < $this->config->getMaxSends((int) $row['website_id']);
    }

    /**
     * Creation time before which a website's row is too old to send.
     *
     * @param int $websiteId
     * @return string
     */
    private function getExpiryCutoff(int $websiteId): string
    {
        return $this->dateTime->gmtDate(
            DateTimeFormat::DATETIME_PHP_FORMAT,
            $this->dateTime->gmtTimestamp() - $this->config->getGiveUpHours($websiteId) * 3600
        );
    }

    /**
     * @param int $minutes
     * @return string
     */
    private function minutesFromNow(int $minutes): string
    {
        return $this->dateTime->gmtDate(
            DateTimeFormat::DATETIME_PHP_FORMAT,
            $this->dateTime->gmtTimestamp() + $minutes * self::SECONDS_PER_MINUTE
        );
    }
}
