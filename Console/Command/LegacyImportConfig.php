<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Copies the connection of the legacy Centraliza modules (Centraliza_Base and friends) to this one:
 * the API key, into the production slot (the legacy modules only talk to production), and the websites
 * the legacy integration was on. Optionally turns off the legacy catalog notifications, the one legacy
 * feature this module replaces.
 *
 * The legacy modules stay installed and enabled: order status messages, abandoned cart and the cart
 * recovery link still live there. Disabling them in config.php would also make the next setup:upgrade
 * drop their tables, because the schema whitelist still lists them.
 */
class LegacyImportConfig extends Command
{
    private const OPTION_CONNECTION_ID = 'connection-id';
    private const OPTION_WEBSITE = 'website';
    private const OPTION_DISABLE_LEGACY_CATALOG = 'disable-legacy-catalog';
    private const OPTION_DRY_RUN = 'dry-run';

    private const LEGACY_ENABLED = 'centraliza/general/enabled';
    private const LEGACY_API_KEY = 'centraliza/general/api_key';

    /** Legacy catalog notifications (product, price, inventory): replaced by this module's streams. */
    private const LEGACY_CATALOG_SWITCHES = [
        'centraliza/notification/general/enabled',
    ];

    private const ENABLED = 'centraliza_connector/general/enabled';
    private const API_KEY = 'centraliza_connector/general/api_key_production';
    private const CONNECTION_ID = 'centraliza_connector/general/connection_id_production';
    private const ENVIRONMENT = 'centraliza_connector/general/environment';
    private const MODE = 'centraliza_connector/general/mode';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor,
        private readonly WriterInterface $writer,
        private readonly ResourceConnection $resource,
        private readonly TypeListInterface $cacheTypes
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('centraliza:legacy:import-config')
            ->setDescription('Copies the API key and enabled websites of the legacy Centraliza modules to Centraliza Connector (production)')
            ->addOption(
                self::OPTION_CONNECTION_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Production channel ID (app.centraliza.ai > Settings > Channels); the legacy modules have none'
            )
            ->addOption(
                self::OPTION_WEBSITE,
                null,
                InputOption::VALUE_REQUIRED,
                'Website code to take the key from, when websites use different keys'
            )
            ->addOption(
                self::OPTION_DISABLE_LEGACY_CATALOG,
                null,
                InputOption::VALUE_NONE,
                'Also turn off the legacy catalog notifications; order status, abandoned cart and cart recovery stay on'
            )
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Show what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $onlyWebsite = trim((string)$input->getOption(self::OPTION_WEBSITE));
        $connectionId = trim((string)$input->getOption(self::OPTION_CONNECTION_ID));

        if ($connectionId !== '' && !preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $connectionId)) {
            $output->writeln(sprintf('<error>Connection ID "%s" is not a UUID.</error>', $connectionId));

            return Command::FAILURE;
        }

        /** @var array<int, array{code: string, enabled: bool, key: string}> $legacy */
        $legacy = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $legacy[$websiteId] = [
                'code' => (string)$website->getCode(),
                'enabled' => $this->scopeConfig->isSetFlag(self::LEGACY_ENABLED, ScopeInterface::SCOPE_WEBSITE, $websiteId),
                'key' => $this->legacyKey($websiteId),
            ];
        }

        if ($onlyWebsite !== '' && !in_array($onlyWebsite, array_column($legacy, 'code'), true)) {
            $output->writeln(sprintf('<error>Website "%s" does not exist.</error>', $onlyWebsite));

            return Command::FAILURE;
        }

        $keys = [];
        foreach ($legacy as $website) {
            if ($website['key'] !== '' && ($onlyWebsite === '' || $website['code'] === $onlyWebsite)) {
                $keys[$website['key']] = true;
            }
        }
        if ($keys === []) {
            $output->writeln('<error>No website has a legacy API key (centraliza/general/api_key). Nothing to import.</error>');

            return Command::FAILURE;
        }
        if (count($keys) > 1) {
            $output->writeln(
                '<error>Websites use different legacy API keys and Centraliza Connector holds one connection. '
                . 'Pick one with --website=<code>.</error>'
            );

            return Command::FAILURE;
        }
        $key = (string)array_key_first($keys);

        // Websites where the legacy integration was live with this key. Only these are turned on and nothing
        // is turned off, so running the command again never undoes the first run.
        $enable = [];
        foreach ($legacy as $websiteId => $website) {
            if ($website['enabled'] && $website['key'] === $key) {
                $enable[] = $websiteId;
            }
        }

        $table = new Table($output);
        $table->setHeaders(['Website', 'Legacy enabled', 'Legacy key', 'Connector enabled']);
        foreach ($legacy as $websiteId => $website) {
            $table->addRow([
                $website['code'],
                $website['enabled'] ? 'yes' : 'no',
                $website['key'] === '' ? '-' : $this->fingerprint($website['key']),
                in_array($websiteId, $enable, true) ? 'yes (set now)' : 'unchanged',
            ]);
        }
        $table->render();

        $writes = [[self::API_KEY, $this->encryptor->encrypt($key), ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]];
        foreach ($enable as $websiteId) {
            $writes[] = [self::ENABLED, '1', ScopeInterface::SCOPE_WEBSITES, $websiteId];
        }
        if ($connectionId !== '') {
            $writes[] = [self::CONNECTION_ID, $connectionId, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
        }
        if ($input->getOption(self::OPTION_DISABLE_LEGACY_CATALOG)) {
            foreach ($this->legacyCatalogScopes() as [$path, $scope, $scopeId]) {
                $writes[] = [$path, '0', $scope, $scopeId];
            }
        }

        foreach ($writes as [$path, $value, $scope, $scopeId]) {
            $shown = $path === self::API_KEY ? 'key ' . $this->fingerprint($key) . ' (encrypted)' : $value;
            $output->writeln(sprintf('%s %s [%s %d] = %s', $dryRun ? 'would set' : 'set', $path, $scope, $scopeId, $shown));
            if (!$dryRun) {
                $this->writer->save($path, $value, $scope, $scopeId);
            }
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing was written.</comment>');

            return Command::SUCCESS;
        }
        $this->cacheTypes->cleanType(ConfigCache::TYPE_IDENTIFIER);

        if ($enable === []) {
            $output->writeln('<comment>The legacy integration is off on every website, so no website was enabled here.</comment>');
        }
        if ($connectionId === '' && trim((string)$this->scopeConfig->getValue(self::CONNECTION_ID)) === '') {
            $output->writeln('<comment>Production channel ID is still empty: set it with --connection-id before going live.</comment>');
        }
        $output->writeln(sprintf(
            '<info>Done. Environment is "%s" and mode is "%s"; switch with bin/magento config:set %s live.</info>',
            (string)$this->scopeConfig->getValue(self::ENVIRONMENT),
            (string)$this->scopeConfig->getValue(self::MODE),
            self::MODE
        ));

        return Command::SUCCESS;
    }

    private function legacyKey(int $websiteId): string
    {
        $stored = (string)$this->scopeConfig->getValue(self::LEGACY_API_KEY, ScopeInterface::SCOPE_WEBSITE, $websiteId);
        if ($stored === '') {
            return '';
        }

        try {
            return trim($this->encryptor->decrypt($stored));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The default scope plus every scope that stores its own value for a legacy switch, so no override keeps it on.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function legacyCatalogScopes(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('core_config_data'), ['path', 'scope', 'scope_id'])
            ->where('path IN (?)', self::LEGACY_CATALOG_SWITCHES)
            ->where('scope <> ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        $scopes = [];
        foreach (self::LEGACY_CATALOG_SWITCHES as $path) {
            $scopes[] = [$path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
        }
        foreach ($connection->fetchAll($select) as $row) {
            $scopes[] = [(string)$row['path'], (string)$row['scope'], (int)$row['scope_id']];
        }

        return $scopes;
    }

    /** Enough to tell keys apart in the output without printing them. */
    private function fingerprint(string $key): string
    {
        return 'sha256:' . substr(hash('sha256', $key), 0, 8);
    }
}
