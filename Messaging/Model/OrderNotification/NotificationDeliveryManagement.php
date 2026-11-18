<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification;

use Centraliza\Connector\Messaging\Api\NotificationDeliveryManagementInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class NotificationDeliveryManagement implements NotificationDeliveryManagementInterface
{
    private const ACL_RESOURCE = 'Centraliza_Notification::delivery_callback';

    /**
     * @param NotificationResource $notificationResource
     * @param UserContextInterface $userContext
     * @param LoggerInterface $logger
     * @param LoggerInterface $debugLogger written only while the Base Debug Log is on
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly UserContextInterface $userContext,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $debugLogger
    ) {
    }

    /**
     * @param string $id
     * @param bool $sent
     * @return bool
     * @throws AuthorizationException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function markSent(string $id, bool $sent): bool
    {
        // Only an integration may answer for Centraliza: admin roles saved before install inherit the ACL resource.
        $userType = (int) $this->userContext->getUserType();
        if ($userType !== UserContextInterface::USER_TYPE_INTEGRATION) {
            // A security event: the caller's type and id identify no credential.
            $this->logger->warning(
                'Centraliza delivery callback rejected: caller is not an integration',
                ['user_type' => $userType, 'user_id' => (int) $this->userContext->getUserId()]
            );

            throw new AuthorizationException(
                __("The consumer isn't authorized to access %resources.", ['resources' => self::ACL_RESOURCE])
            );
        }

        // Before any query, so a malformed id never reaches SQL or the log, and the 404 can quote it.
        if (!preg_match(Notification::EXTERNAL_ID_PATTERN, $id)) {
            throw new InputException(__('The notification id is not valid.'));
        }

        // The column's collation ignores case, Centraliza's ids do not: another casing is another id.
        $row = $this->notificationResource->findByExternalId($id);
        if (empty($row) || $row['external_id'] !== $id) {
            // Safe to log: it matched the pattern. It is the one trace of a callback for a missing row.
            $this->logger->warning('Centraliza delivery callback for an unknown notification id', ['id' => $id]);

            throw new NoSuchEntityException(__('No notification matches id "%1".', $id));
        }

        // A repeated callback is answered true and changes nothing; markCallback() checks the state again.
        if (!in_array($row['status'], Notification::CALLBACK_SOURCE_STATUSES, true)) {
            return true;
        }

        if (!$this->notificationResource->markCallback((int) $row['entity_id'], $sent)) {
            return true;
        }

        $this->debugLogger->info(
            'Centraliza delivery callback recorded',
            ['id' => $id, 'increment_id' => $row['increment_id'], 'sent' => $sent]
        );

        return true;
    }
}
