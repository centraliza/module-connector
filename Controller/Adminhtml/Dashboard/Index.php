<?php

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Connector::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        if (!$page instanceof Page) {
            throw new \LogicException('The admin page result was expected.');
        }
        $page->setActiveMenu('Centraliza_Connector::centraliza_dashboard');
        $page->getConfig()->getTitle()->prepend((string)__('Centraliza'));

        return $page;
    }
}
