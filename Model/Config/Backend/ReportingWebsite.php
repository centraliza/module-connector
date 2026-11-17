<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Backend;

use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * One website reports to Centraliza at a time, with its own environment, key and channel: turning a second
 * one on, directly or through Default Config, is refused.
 */
class ReportingWebsite extends Value
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        if ((string)$this->getValue() === '1') {
            $turnedOn = $this->websitesTurnedOnAfterSave();
            if (count($turnedOn) > 1) {
                throw new LocalizedException(__(
                    'Centraliza Connector reports one website at a time, and this would turn on %1. Set it to Yes on one website only, and keep Default Config on No.',
                    implode(', ', array_map(fn (int $id): string => $this->websiteName($id), $turnedOn))
                ));
            }
        }

        return parent::beforeSave();
    }

    /**
     * @return int[]
     */
    private function websitesTurnedOnAfterSave(): array
    {
        $savedOnWebsite = $this->getScope() === ScopeInterface::SCOPE_WEBSITES ? (int)$this->getScopeId() : null;
        $ownValues = $savedOnWebsite === null ? $this->websiteValues() : [];

        $turnedOn = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if ($savedOnWebsite !== null) {
                // Saved on one website: the others keep what they have now, own or inherited.
                $isOn = $websiteId === $savedOnWebsite
                    || $this->_config->isSetFlag(Settings::XML_PATH_ENABLED, ScopeInterface::SCOPE_WEBSITE, $websiteId);
            } else {
                // Saved on Default Config: a website without its own value inherits the Yes.
                $isOn = !array_key_exists($websiteId, $ownValues) || $ownValues[$websiteId] === '1';
            }
            if ($isOn) {
                $turnedOn[] = $websiteId;
            }
        }

        return $turnedOn;
    }

    /**
     * @return array<int, string> the value each website stores itself, by website id
     */
    private function websiteValues(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('core_config_data'), ['scope_id', 'value'])
                ->where('path = ?', Settings::XML_PATH_ENABLED)
                ->where('scope = ?', ScopeInterface::SCOPE_WEBSITES)
        );

        $values = [];
        foreach ($rows as $websiteId => $value) {
            $values[(int)$websiteId] = (string)$value;
        }

        return $values;
    }

    private function websiteName(int $websiteId): string
    {
        return (string)$this->storeManager->getWebsite($websiteId)->getName();
    }
}
