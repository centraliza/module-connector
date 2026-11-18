<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\OrderNotification;

use Centraliza\Connector\Messaging\Model\Config\Backend\SerializedRows;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order\Config as OrderConfig;

class NotifiedStatuses extends SerializedRows
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param OrderConfig $orderConfig
     * @param Json $serializer
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly OrderConfig $orderConfig,
        Json $serializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $serializer,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Validate every notified status row before the serialized value is stored.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        // The form checks only client side: a crafted POST or config:set (a JSON string) reach here with any value.
        $rows = $this->readRows($this->getValue(), __('The notified statuses value is not valid.'));
        $knownStatuses = $this->orderConfig->getStatuses();
        $seen = [];
        $validRows = [];
        foreach ($rows as $key => $row) {
            if ($key === '__empty' || !is_array($row)) {
                continue;
            }

            $status = '';
            if (is_scalar($row['status'] ?? null)) {
                $status = (string) $row['status'];
            }

            if (empty($status)) {
                throw new LocalizedException(__('Choose an order status for every row.'));
            }

            // Checked against the real list: the value is echoed in the messages below.
            if (!isset($knownStatuses[$status])) {
                throw new LocalizedException(
                    __('The order status in the table does not exist in this store.')
                );
            }

            // A status listed twice adds nothing and usually hides a typo in the row the administrator meant.
            if (isset($seen[$status])) {
                throw new LocalizedException(__('Order status "%1" is listed more than once.', $status));
            }

            $seen[$status] = true;

            // The grid uses the row key as a DOM id: an invalid key (a config:set index) or a taken one is replaced.
            if (!is_string($key) || !preg_match(self::ROW_KEY_PATTERN, $key) || isset($validRows[$key])) {
                $key = '_' . hash('crc32b', $status);
                while (isset($validRows[$key])) {
                    $key .= '_';
                }
            }
            $validRows[$key] = ['status' => $status];
        }

        $this->setValue($validRows);

        return parent::beforeSave();
    }
}
