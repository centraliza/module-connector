<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Telemetry;

/**
 * The New Relic PHP agent API, behind one class so the rest can be tested
 * without the extension. Every method does nothing when the agent is not loaded.
 */
class NewRelicAgent
{
    public function isAvailable(): bool
    {
        return extension_loaded('newrelic') && function_exists('newrelic_record_custom_event');
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function recordCustomEvent(string $name, array $attributes): void
    {
        if ($this->isAvailable()) {
            $this->call('newrelic_record_custom_event', $name, $attributes);
        }
    }

    /**
     * A queue consumer is one endless PHP process, and the agent only sends
     * what it gathered when a transaction ends. Each message is therefore its
     * own background transaction; the idle wait before it is thrown away.
     */
    public function startBackgroundTransaction(string $name): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $this->call('newrelic_end_transaction', true);
        $this->call('newrelic_start_transaction', (string)ini_get('newrelic.appname'));
        $this->call('newrelic_background_job', true);
        $this->call('newrelic_name_transaction', $name);
    }

    public function endTransaction(): void
    {
        if ($this->isAvailable()) {
            $this->call('newrelic_end_transaction');
        }
    }

    /**
     * Called by name: the functions exist only where the extension is loaded.
     */
    private function call(string $function, mixed ...$arguments): void
    {
        if (is_callable($function)) {
            $function(...$arguments);
        }
    }
}
