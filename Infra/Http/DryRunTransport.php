<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Http;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Core\Port\TransportInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Throwable;

/**
 * Sends nothing. Writes the request it would have made to a log, one JSON
 * object per line, so the traffic can be reviewed before the real transport
 * is switched on.
 */
class DryRunTransport implements TransportInterface
{
    public const LOG_FILE = 'log/centraliza_connector_dry_run.jsonl';
    private const URL = 'https://api.centraliza.ai/v1/admin/events';
    private const UNSET_CONNECTION = '<connection-id>';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly SettingsInterface $settings,
        private readonly ClockInterface $clock
    ) {
    }

    public function send(ConnectionEvent $event): Outcome
    {
        try {
            $line = json_encode([
                'logged_at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()),
                'method' => 'POST',
                'url' => self::URL,
                'headers' => [
                    'Authorization' => 'Bearer csk_***',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => $event->toRequestBody($this->settings->connectionId() ?: self::UNSET_CONNECTION),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile(self::LOG_FILE, $line . "\n", 'a');
        } catch (Throwable $e) {
            return Outcome::unavailable(null, 'Dry run log could not be written.');
        }

        return Outcome::of(OutcomeKind::Accepted);
    }
}
