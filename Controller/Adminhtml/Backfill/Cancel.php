<?php

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\Backfill;

use Centraliza\Connector\Backfill\BackfillService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Stops putting records of a whole-stream send on the queue. What is already on the queue is still sent.
 */
class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Connector::manage';

    public function __construct(
        Context $context,
        private readonly BackfillService $backfills
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        if ($this->backfills->cancel((int)$this->getRequest()->getParam('id'))) {
            $this->messageManager->addSuccessMessage((string)__('Send cancelled. What was already on the queue is still sent.'));
        } else {
            $this->messageManager->addNoticeMessage((string)__('That send had already ended.'));
        }

        return $this->resultRedirectFactory->create()->setPath('*/dashboard/index');
    }
}
