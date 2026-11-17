<?php

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\Backfill;

use Centraliza\Connector\Backfill\BackfillService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks for every record of one stream to be sent, and puts the first pages on the queue right away so a small
 * stream is done before the page reloads; the cron does the rest.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Connector::manage';

    private const NOW_SECONDS = 5;
    private const NOW_RECORDS = 2000;

    public function __construct(
        Context $context,
        private readonly BackfillService $backfills,
        private readonly Session $session,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $stream = (string)$this->getRequest()->getParam('stream');
        try {
            $user = $this->session->getUser();
            $asked = $this->backfills->request($stream, $user ? (string)$user->getUserName() : '');
            $this->backfills->run(self::NOW_SECONDS, self::NOW_RECORDS);
            $this->messageManager->addSuccessMessage((string)__(
                'Sending every %1: %2 record(s) go on the queue, then out within the request limit.',
                $stream,
                $asked['total']
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not start a whole-stream send.', ['exception' => $e]);
            $this->messageManager->addErrorMessage((string)__('The send could not be started. See the exception log.'));
        }

        return $this->resultRedirectFactory->create()->setPath('*/dashboard/index');
    }
}
