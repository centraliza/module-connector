<?php

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\Quarantine;

use Centraliza\Connector\Infra\Queue\QuarantineRequeue;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts every quarantined event back on the queue, starting from zero attempts.
 */
class Retry extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Connector::manage';

    public function __construct(
        Context $context,
        private readonly QuarantineRequeue $requeue,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $result = $this->requeue->all();
            $this->messageManager->addSuccessMessage((string)__('%1 quarantined event(s) put back on the queue.', $result['requeued']));
            if ($result['unreadable'] > 0) {
                $this->messageManager->addWarningMessage((string)__(
                    '%1 event(s) cannot be read and stay in quarantine: see bin/magento centraliza:quarantine:list.',
                    $result['unreadable']
                ));
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not requeue the quarantine.', ['exception' => $e]);
            $this->messageManager->addErrorMessage((string)__('The quarantine could not be put back on the queue. See the exception log.'));
        }

        return $this->resultRedirectFactory->create()->setPath('*/dashboard/index');
    }
}
