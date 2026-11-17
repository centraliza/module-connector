<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend;

use InvalidArgumentException;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

abstract class SerializedRows extends ArraySerialized
{
    /**
     * The admin grid's row key. D: without it "$" also matches before a trailing newline.
     */
    public const ROW_KEY_PATTERN = '/^_\w+$/D';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
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
        private readonly Json $serializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $serializer
        );
    }

    /**
     * Read the grid rows from an array (admin form) or a JSON string (config:set).
     *
     * @param mixed $value
     * @param Phrase $invalidMessage
     * @return array
     * @throws LocalizedException
     */
    protected function readRows(mixed $value, Phrase $invalidMessage): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (empty($value)) {
            return [];
        }

        if (!is_string($value)) {
            throw new LocalizedException($invalidMessage);
        }

        try {
            $rows = $this->serializer->unserialize($value);
        } catch (InvalidArgumentException) {
            throw new LocalizedException($invalidMessage);
        }

        // A scalar such as config:set '5' would otherwise clear every row.
        if (!is_array($rows)) {
            throw new LocalizedException($invalidMessage);
        }

        return $rows;
    }
}
