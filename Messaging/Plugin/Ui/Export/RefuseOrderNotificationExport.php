<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Plugin\Ui\Export;

use Magento\Backend\App\Action;
use Magento\Framework\Exception\NotFoundException;

class RefuseOrderNotificationExport
{
    private const LISTING_NAMESPACE = 'centraliza_connector_notification_listing';

    /**
     * The order notification grid has no export: Centraliza's error text would reach a spreadsheet,
     * where the core CSV escaping leaves a leading "@", TAB or CR active as a formula.
     *
     * @param Action $subject
     * @return null
     * @throws NotFoundException
     */
    public function beforeExecute(Action $subject)
    {
        if ((string) $subject->getRequest()->getParam('namespace') === self::LISTING_NAMESPACE) {
            throw new NotFoundException(__('Page not found.'));
        }

        return null;
    }
}
