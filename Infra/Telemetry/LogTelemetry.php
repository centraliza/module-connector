<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Telemetry;

use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\TelemetryInterface;
use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Throwable;

/**
 * Writes each telemetry event as one JSON line, for when there is no New
 * Relic to look at: a load test, a local environment, a support session.
 * Off unless centraliza_connector/general/telemetry_log is set.
 */
class LogTelemetry implements TelemetryInterface
{
    public const LOG_FILE = 'log/centraliza_connector_telemetry.jsonl';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Settings $settings,
        private readonly ClockInterface $clock
    ) {
    }

    public function event(string $name, array $attributes): void
    {
        try {
            if (!$this->settings->logsTelemetry()) {
                return;
            }
            $line = json_encode(
                ['at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()), 'event' => $name] + $attributes,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile(self::LOG_FILE, $line . "\n", 'a');
        } catch (Throwable) {
            // Telemetry must not get in the way of what it observes.
        }
    }
}
