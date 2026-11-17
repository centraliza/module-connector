<?php

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Infra\Http\DryRunTransport;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * In simulation mode a message is written to the same log as the connection events instead of being sent,
 * so a store being tested (homologation) never messages a customer.
 */
class DryRunLog
{
    /**
     * @param Filesystem $filesystem
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, mixed> $options the json body and query of the request
     * @return void
     * @throws \Throwable when the log cannot be written; the caller reads it as never sent
     */
    public function write(string $method, string $url, array $options): void
    {
        $line = json_encode([
            'logged_at' => $this->dateTime->gmtDate('Y-m-d\TH:i:s\Z'),
            'method' => $method,
            'url' => $url,
            'headers' => [
                'Authorization' => 'Bearer csk_***',
                'Content-Type' => 'application/json',
            ],
            'query' => $options['query'] ?? null,
            'body' => $options['json'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)
            ->writeFile(DryRunTransport::LOG_FILE, $line . "\n", 'a');
    }
}
