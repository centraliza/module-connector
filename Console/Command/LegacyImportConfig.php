<?php

declare(strict_types=1);

namespace Centraliza\Connector\Console\Command;

use Centraliza\Connector\Infra\Config\Settings;
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
 * Copies the connection of the legacy Centraliza modules (Centraliza_Base and friends) to one website of this
 * module: the API key, into the production slot (the legacy modules only talk to production), the website's
 * switch when the legacy integration was on there, and the settings of the messages (order notifications, abandoned
 * carts, cart recovery link) as the website reads them.
 * Everything is written at that website's scope: this module reports one website at a time. Optionally turns off
 * the legacy catalog notifications, which this module replaces.
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

    /** Order notifications, abandoned carts and the cart recovery link (Centraliza_Sales), legacy path => path here. */
    private const MESSAGING_PATHS = [
        'centraliza/order_notification/enabled' => 'centraliza_connector/order_notification/enabled',
        'centraliza/order_notification/order_created_enabled' => 'centraliza_connector/order_notification/order_created_enabled',
        'centraliza/order_notification/notified_statuses' => 'centraliza_connector/order_notification/notified_statuses',
        'centraliza/order_notification/retry_enabled' => 'centraliza_connector/order_notification/retry_enabled',
        'centraliza/order_notification/give_up_hours' => 'centraliza_connector/order_notification/give_up_hours',
        'centraliza/order_notification/max_attempts' => 'centraliza_connector/order_notification/max_attempts',
        'centraliza/abandoned_cart/enabled' => 'centraliza_connector/abandoned_cart/enabled',
        'centraliza/abandoned_cart/abandon_minutes' => 'centraliza_connector/abandoned_cart/abandon_minutes',
        'centraliza/abandoned_cart/send_limit_minutes' => 'centraliza_connector/abandoned_cart/send_limit_minutes',
        'centraliza/abandoned_cart/max_notifications' => 'centraliza_connector/abandoned_cart/max_notifications',
        'centraliza/cart_redirect/enabled' => 'centraliza_connector/cart_redirect/enabled',
    ];

    private const API_KEY = 'centraliza_connector/general/api_key_production';
    private const CONNECTION_ID = 'centraliza_connector/general/connection_id_production';
    private const MODE = 'centraliza_connector/general/mode';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor,
        private readonly WriterInterface $writer,
        private readonly ResourceConnection $resource,
        private readonly TypeListInterface $cacheTypes,
        private readonly Settings $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('centraliza:legacy:import-config')
            ->setDescription('Copies the API key and message settings of the legacy Centraliza modules to one website of Centraliza Connector (production)')
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
                'Website code to import; needed when the legacy integration is on for more than one website'
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

        $target = $this->targetWebsite($legacy, $onlyWebsite, $output);
        if ($target === null) {
            return Command::FAILURE;
        }
        $key = $legacy[$target]['key'];
        if ($key === '') {
            $output->writeln(sprintf(
                '<error>Website "%s" has no legacy API key (centraliza/general/api_key). Nothing to import.</error>',
                $legacy[$target]['code']
            ));

            return Command::FAILURE;
        }

        $others = array_values(array_diff($this->settings->websitesTurnedOn(), [$target]));
        if ($others !== []) {
            $output->writeln(sprintf(
                '<error>Centraliza Connector already reports website "%s", and it reports one website at a time. Turn it off there first.</error>',
                $legacy[$others[0]]['code'] ?? (string)$others[0]
            ));

            return Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(['Website', 'Legacy enabled', 'Legacy key', 'Imported']);
        foreach ($legacy as $websiteId => $website) {
            $table->addRow([
                $website['code'],
                $website['enabled'] ? 'yes' : 'no',
                $website['key'] === '' ? '-' : $this->fingerprint($website['key']),
                $websiteId === $target ? 'yes' : 'no',
            ]);
        }
        $table->render();

        $writes = [[self::API_KEY, $this->encryptor->encrypt($key), ScopeInterface::SCOPE_WEBSITES, $target]];
        // Only turned on where the legacy integration was on; nothing is turned off, so a second run undoes nothing.
        if ($legacy[$target]['enabled']) {
            $writes[] = [Settings::XML_PATH_ENABLED, '1', ScopeInterface::SCOPE_WEBSITES, $target];
        }
        if ($connectionId !== '') {
            $writes[] = [self::CONNECTION_ID, $connectionId, ScopeInterface::SCOPE_WEBSITES, $target];
        }
        // As the website reads them, so a value the legacy website inherited from Default Config comes along too.
        foreach (self::MESSAGING_PATHS as $legacyPath => $path) {
            $value = $this->scopeConfig->getValue($legacyPath, ScopeInterface::SCOPE_WEBSITE, $target);
            if ($value !== null && $value !== '') {
                $writes[] = [$path, (string)$value, ScopeInterface::SCOPE_WEBSITES, $target];
            }
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

        if (!$legacy[$target]['enabled']) {
            $output->writeln('<comment>The legacy integration is off on this website, so it was not turned on here.</comment>');
        }
        if ($connectionId === '' && trim((string)$this->settings->get(self::CONNECTION_ID, $target)) === '') {
            $output->writeln('<comment>Production channel ID is still empty: set it with --connection-id before going live.</comment>');
        }
        $output->writeln(sprintf(
            '<info>Done. Website "%s" uses environment "%s" in mode "%s"; switch with bin/magento config:set --scope=websites --scope-code=%s %s live.</info>',
            $legacy[$target]['code'],
            $this->settings->environment($target),
            (string)$this->settings->get(self::MODE, $target),
            $legacy[$target]['code'],
            self::MODE
        ));

        return Command::SUCCESS;
    }

    /**
     * The website named with --website, or the only one where the legacy integration is on with a key.
     *
     * @param array<int, array{code: string, enabled: bool, key: string}> $legacy
     */
    private function targetWebsite(array $legacy, string $code, OutputInterface $output): ?int
    {
        if ($code !== '') {
            foreach ($legacy as $websiteId => $website) {
                if ($website['code'] === $code) {
                    return $websiteId;
                }
            }
            $output->writeln(sprintf('<error>Website "%s" does not exist.</error>', $code));

            return null;
        }

        $candidates = array_keys(array_filter($legacy, static fn (array $website): bool => $website['enabled'] && $website['key'] !== ''));
        if (count($candidates) === 1) {
            return (int)$candidates[0];
        }
        if ($candidates === []) {
            $output->writeln('<error>No website has the legacy integration on with an API key. Name one with --website=<code>.</error>');
        } else {
            $output->writeln(sprintf(
                '<error>The legacy integration is on for %s, and Centraliza Connector reports one website at a time. Pick one with --website=<code>.</error>',
                implode(', ', array_map(static fn (int $id): string => $legacy[$id]['code'], $candidates))
            ));
        }

        return null;
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
