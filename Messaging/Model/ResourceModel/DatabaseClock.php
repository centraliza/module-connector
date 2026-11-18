<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DatabaseClock
{
    /**
     * How long a read of the database clock is trusted. A clock set wrong is corrected by NTP in one
     * step, not gradually: rereading every few minutes picks such a step up within minutes, while the
     * cost stays one query per run (chosen value, not measured).
     */
    private const REFRESH_SECONDS = 300;

    /**
     * The smallest difference between the two clocks worth correcting, in seconds (chosen value, not
     * measured): the waits it protects are counted in minutes.
     */
    private const MINIMUM_OFFSET_SECONDS = 60;

    /**
     * The largest difference still treated as a clock to correct, in seconds (chosen value, not
     * measured). Past an hour the host is not merely out of sync, and an uncapped value lands the
     * corrected date outside the column's range, where it reads as 1970 or 2083 instead of as missing.
     */
    public const MAXIMUM_OFFSET_SECONDS = 3600;

    private bool $warnedAboutDistance = false;

    private ?int $baseTimestamp = null;

    private ?float $baseMonotonicNanoseconds = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The current UTC timestamp by the database clock.
     *
     * @return int
     */
    public function getUtcTimestamp(): int
    {
        // One clock, the database's: the abandonment waits compare against columns the database wrote
        // (quote.updated_at, created_at), and an app server running ahead of the database would shrink
        // them by the difference, sending while the customer is still around. Elapsed time is counted
        // monotonically, so a wall-clock step on the app host cannot move "now" either.
        $elapsedSeconds = $this->baseMonotonicNanoseconds === null
            ? null
            : (hrtime(true) - $this->baseMonotonicNanoseconds) / 1e9;
        if ($elapsedSeconds === null || $elapsedSeconds >= self::REFRESH_SECONDS) {
            $this->baseTimestamp = $this->fetchUtcTimestamp();
            $this->baseMonotonicNanoseconds = (float) hrtime(true);
            $elapsedSeconds = 0.0;
        }

        return $this->baseTimestamp + (int) $elapsedSeconds;
    }

    /**
     * How far the app server's clock runs ahead of the database's, in seconds; negative when behind.
     *
     * @return int
     */
    public function getAppOffsetSeconds(): int
    {
        $offset = time() - $this->getUtcTimestamp();

        // Under a minute is below the resolution the waits are counted in, so there is nothing to gain
        // from correcting it.
        if (abs($offset) < self::MINIMUM_OFFSET_SECONDS) {
            return 0;
        }

        // Left uncorrected rather than guessed at: the correction holds only while one host drifted, and
        // moving a visit by an hour on a premise this shaky is the one way this code could send to a
        // customer who is still shopping. The wait stays stretched until someone sets the clock, which is
        // the harmless half of being wrong. Warned once: this runs for every row of a send round.
        if (abs($offset) > self::MAXIMUM_OFFSET_SECONDS) {
            if (!$this->warnedAboutDistance) {
                $this->warnedAboutDistance = true;
                $this->logger->warning(
                    'Centraliza abandoned cart: the application and database clocks are more than an hour apart',
                    ['offset_seconds' => $offset]
                );
            }

            return 0;
        }

        return $offset;
    }

    /**
     * The current UTC date and time by the database clock.
     *
     * @return string
     */
    public function getUtcDate(): string
    {
        return gmdate('Y-m-d H:i:s', $this->getUtcTimestamp());
    }

    /**
     * @return int
     */
    private function fetchUtcTimestamp(): int
    {
        $databaseTime = (string) $this->resourceConnection->getConnection()->fetchOne('SELECT UTC_TIMESTAMP()');
        // An empty string would make strtotime(' UTC') read the app clock, the one this class replaces.
        $timestamp = $databaseTime === '' ? false : strtotime($databaseTime . ' UTC');

        // Fail loud: a false here would silently turn every "now" into 1970 and corrupt the rows.
        if ($timestamp === false) {
            throw new RuntimeException('The database clock could not be read.');
        }

        return $timestamp;
    }
}
