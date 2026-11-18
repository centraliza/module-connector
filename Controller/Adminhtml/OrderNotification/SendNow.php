<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\OrderNotification;

use Centraliza\Connector\Messaging\Cron\OrderNotification\SendNotifications;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class SendNow extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Notification::notification_send_now';

    /**
     * @param Context $context
     * @param SendNotifications $sendNotifications
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly SendNotifications $sendNotifications,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Send now needs the log too: a role granted only the child resource must not message customers.
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
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('*/*/index');

        $user = $this->_auth->getUser();
        $this->logger->info('Centraliza notification send round run by an administrator', [
            'admin_user_id' => $user?->getId(),
            'admin_username' => $user?->getUserName(),
        ]);

        try {
            // The cron round itself: same key locks, quota and retry rules as the scheduled run.
            $this->sendNotifications->execute();
            $this->messageManager->addSuccessMessage(
                __('The send round finished. The grid shows the status of each row.')
            );
        } catch (Throwable $exception) {
            $this->logger->error('Centraliza notification send round run by an administrator failed', [
                'exception_class' => $exception::class,
                'origin' => $exception->getFile() . ':' . $exception->getLine(),
            ]);
            $this->messageManager->addErrorMessage(__('The send round could not run. Try again.'));
        }

        return $resultRedirect;
    }
}
