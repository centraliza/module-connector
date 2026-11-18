<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\OrderNotification;

use Centraliza\Connector\Messaging\Api\ResendNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\OrderNotification\ResendSummary;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;
use Throwable;

class MassResend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Notification::notification_resend';

    /**
     * Each row resent is a WhatsApp to a customer: an unfiltered "Select All" must not message the whole backlog.
     */
    private const MAX_SELECTION = 500;

    private const LISTING_NAMESPACE = 'centraliza_connector_notification_listing';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ResendNotificationServiceInterface $resendService
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResendNotificationServiceInterface $resendService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Resend needs the log too: a role granted only the child resource must not message customers.
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed(Index::ADMIN_RESOURCE);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('*/*/index');

        try {
            // The Filter builds the selection from the namespace's grid: a forged one is refused first.
            if ($this->getRequest()->getParam('namespace') !== self::LISTING_NAMESPACE) {
                throw new LocalizedException(__('These notifications cannot be resent.'));
            }

            // Applied to this module's collection: whatever ids the request carries, only its rows are resent.
            $ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
            if (count($ids) > self::MAX_SELECTION) {
                throw new LocalizedException(__(
                    '%1 notifications selected. Resend at most %2 at a time: filter the grid first.',
                    count($ids),
                    self::MAX_SELECTION
                ));
            }

            $summary = $this->resendService->resend($ids);
            $this->logResend(count($ids), $summary);
            $this->addSummaryMessages($summary);
        } catch (LocalizedException $exception) {
            $this->logger->warning('Centraliza notification resend refused', [
                'exception_class' => $exception::class,
                'origin' => $exception->getFile() . ':' . $exception->getLine(),
            ]);
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Centraliza notification resend failed', [
                'exception_class' => $exception::class,
                'origin' => $exception->getFile() . ':' . $exception->getLine(),
            ]);
            $this->messageManager->addErrorMessage(__('The notifications could not be resent. Try again.'));
        }

        return $resultRedirect;
    }

    /**
     * Record who resent how many notifications; only counts, never customer data.
     *
     * @param int $requested
     * @param ResendSummary $summary
     * @return void
     */
    private function logResend(int $requested, ResendSummary $summary): void
    {
        $user = $this->_auth->getUser();
        $this->logger->info('Centraliza notifications resent by an administrator', [
            'admin_user_id' => $user?->getId(),
            'admin_username' => $user?->getUserName(),
            'requested' => $requested,
            'resent' => $summary->getResent(),
            'not_resendable' => $summary->getNotResendable(),
            'expired' => $summary->getExpired(),
            'permanent_error' => $summary->getPermanentError(),
        ]);
    }

    /**
     * @param ResendSummary $summary
     * @return void
     */
    private function addSummaryMessages(ResendSummary $summary): void
    {
        $this->messageManager->addSuccessMessage(
            __('%1 notification(s) scheduled for resend.', $summary->getResent())
        );

        if ($summary->getNotResendable() > 0) {
            $this->messageManager->addWarningMessage(__(
                '%1 notification(s) skipped: only failed or not sent notifications can be resent.',
                $summary->getNotResendable()
            ));
        }

        if ($summary->getExpired() > 0) {
            $this->messageManager->addWarningMessage(__(
                '%1 notification(s) skipped: created before the resend window ("Give up after (hours)").',
                $summary->getExpired()
            ));
        }

        if ($summary->getPermanentError() > 0) {
            $this->messageManager->addWarningMessage(__(
                '%1 notification(s) skipped: they failed with an error a resend cannot fix.',
                $summary->getPermanentError()
            ));
        }
    }
}
