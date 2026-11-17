<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RateLimit extends AbstractDb
{
    /**
     * Platform limit (measured): 60 requests per key in a fixed 60 s window from the first request. The limit
     * itself is configurable (QuotaReservation::PATH_REQUEST_LIMIT) and passed to each call; the window is not.
     */
    public const WINDOW_SECONDS = 60;

    /**
     * A refusal pauses 15 minutes (user decision): a revoked key costs one request per pause, and a passing one heals.
     */
    public const REFUSAL_SECONDS = 900;

    /**
     * No block outlasts a refusal, the longest pause the Base records; also bounds what other modules pass.
     */
    public const MAX_BLOCK_SECONDS = self::REFUSAL_SECONDS;

    /**
     * Chosen retention, not measured: an expired refusal says nothing after a day, an idle key's row after a week.
     */
    private const EXPIRED_REFUSAL_RETENTION_DAYS = 1;
    private const IDLE_ROW_RETENTION_DAYS = 7;

    private const BLOCKED_UNTIL = 'blocked_until';
    private const KEY_REFUSED_UNTIL = 'key_refused_until';
    private const LAST_CHECK_AT = 'last_check_at';
    private const REFUSAL_TABLE = 'centraliza_rate_limit_refusal';

    /**
     * Database clock only, so every server agrees on when a window or a block ends.
     */
    private const WINDOW_EXPIRED = '(window_started_at IS NULL OR window_started_at <= NOW() - INTERVAL %d SECOND)';

    /**
     * @var bool
     */
    protected $_isPkAutoIncrement = false;

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('centraliza_rate_limit', 'api_key_hash');
    }

    /**
     * Reserve one request when the key is not blocked or paused and more than $floor requests are left.
     *
     * @param string $apiKeyHash
     * @param int $floor
     * @param string $route relative API path of the request
     * @param bool $pausedByRefusals false for interactive calls, which a refusal never pauses
     * @param int $limit requests per window
     * @return bool
     */
    public function reserve(string $apiKeyHash, int $floor, string $route, bool $pausedByRefusals, int $limit): bool
    {
        $connection = $this->getConnection();
        $this->ensureRow($apiKeyHash);

        $expired = sprintf(self::WINDOW_EXPIRED, self::WINDOW_SECONDS);
        $where = [
            'api_key_hash = ?' => $apiKeyHash,
            $connection->quoteInto("({$expired} OR used < ?)", $limit - $floor),
            '(blocked_until IS NULL OR blocked_until <= NOW())',
        ];
        if ($pausedByRefusals) {
            $where[] = '(key_refused_until IS NULL OR key_refused_until <= NOW())';
            $routeRefusal = $this->selectRouteRefusal($route, new Expression('1'))
                ->where('route_refusal.refused_until > NOW()');
            $where[] = 'NOT EXISTS (' . $routeRefusal . ')';
        }

        // One statement keeps the reservation atomic across processes. SET runs left to
        // right, so used still reads the old window_started_at.
        $affected = $connection->update(
            $this->getMainTable(),
            [
                'used' => new Expression("IF({$expired}, 1, used + 1)"),
                'window_started_at' => new Expression("IF({$expired}, NOW(), window_started_at)"),
            ],
            $where
        );

        return $affected === 1;
    }

    /**
     * @param string $apiKeyHash
     * @param int $requests
     * @return void
     */
    public function consume(string $apiKeyHash, int $requests): void
    {
        if ($requests <= 0) {
            return;
        }

        $connection = $this->getConnection();
        $connection->update(
            $this->getMainTable(),
            ['used' => new Expression($connection->quoteInto('used + ?', $requests))],
            [
                'api_key_hash = ?' => $apiKeyHash,
                'NOT ' . sprintf(self::WINDOW_EXPIRED, self::WINDOW_SECONDS),
            ]
        );
    }

    /**
     * @param string $apiKeyHash
     * @param int $remaining
     * @param int $limit requests per window
     * @return void
     */
    public function recordServerRemaining(string $apiKeyHash, int $remaining, int $limit): void
    {
        $connection = $this->getConnection();
        $spent = $limit - max(0, min($remaining, $limit));

        // Only ever raised: an older reading must not undo another process's reservation.
        $connection->update(
            $this->getMainTable(),
            ['used' => new Expression($connection->quoteInto('GREATEST(used, ?)', $spent))],
            [
                'api_key_hash = ?' => $apiKeyHash,
                'NOT ' . sprintf(self::WINDOW_EXPIRED, self::WINDOW_SECONDS),
            ]
        );
    }

    /**
     * @param string $apiKeyHash
     * @param int $seconds
     * @return void
     */
    public function block(string $apiKeyHash, int $seconds): void
    {
        $this->extendUntil($apiKeyHash, self::BLOCKED_UNTIL, $seconds);
    }

    /**
     * Pause every route of the key; a shorter pause never shortens one already recorded.
     *
     * @param string $apiKeyHash
     * @param int $seconds
     * @return void
     */
    public function refuseKey(string $apiKeyHash, int $seconds): void
    {
        $this->extendUntil($apiKeyHash, self::KEY_REFUSED_UNTIL, $seconds);
    }

    /**
     * Pause one route of the key; a shorter pause never shortens one already recorded.
     *
     * @param string $apiKeyHash
     * @param string $route
     * @param int $seconds
     * @return void
     */
    public function refuseRoute(string $apiKeyHash, string $route, int $seconds): void
    {
        $connection = $this->getConnection();
        // The wait is read through the key's row, so a refusal never exists without it.
        $this->ensureRow($apiKeyHash);

        $connection->insertOnDuplicate(
            $this->getTable(self::REFUSAL_TABLE),
            [
                'api_key_hash' => $apiKeyHash,
                'route' => $route,
                'refused_until' => new Expression($connection->quoteInto(
                    'NOW() + INTERVAL ? SECOND',
                    $this->boundSeconds($seconds)
                )),
            ],
            ['refused_until' => new Expression('GREATEST(refused_until, VALUES(refused_until))')]
        );
    }

    /**
     * A key that is not refused, the usual case after a 2xx, matches no row and writes nothing.
     *
     * @param string $apiKeyHash
     * @return void
     */
    public function clearKeyRefusal(string $apiKeyHash): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            [self::KEY_REFUSED_UNTIL => null],
            ['api_key_hash = ?' => $apiKeyHash, 'key_refused_until IS NOT NULL']
        );
    }

    /**
     * @param string $apiKeyHash
     * @param string $route
     * @return void
     */
    public function clearRouteRefusal(string $apiKeyHash, string $route): void
    {
        $this->getConnection()->delete(
            $this->getTable(self::REFUSAL_TABLE),
            ['api_key_hash = ?' => $apiKeyHash, 'route = ?' => $route]
        );
    }

    /**
     * @param string $apiKeyHash
     * @param bool $pausedByRefusals false for interactive calls, which a refusal never pauses
     * @param int $limit requests per window
     * @return int requests left in the window; 0 while the key is blocked or, when it pauses the caller, refused
     */
    public function getRemaining(string $apiKeyHash, bool $pausedByRefusals, int $limit): int
    {
        $connection = $this->getConnection();
        $pause = '(blocked_until IS NOT NULL AND blocked_until > NOW())';
        if ($pausedByRefusals) {
            $pause .= ' OR (key_refused_until IS NOT NULL AND key_refused_until > NOW())';
        }
        $remaining = $connection->fetchOne(
            sprintf(
                'SELECT IF(%1$s, 0, IF(%2$s, %3$d, GREATEST(0, %3$d - used)))',
                $pause,
                sprintf(self::WINDOW_EXPIRED, self::WINDOW_SECONDS),
                $limit
            )
            . ' FROM ' . $connection->quoteIdentifier($this->getMainTable())
            . ' WHERE api_key_hash = ?',
            [$apiKeyHash]
        );
        if ($remaining === false) {
            return $limit;
        }

        return (int) $remaining;
    }

    /**
     * @param string $apiKeyHash
     * @param string $route
     * @param bool $pausedByRefusals false for interactive calls, which a refusal never pauses
     * @param bool $windowCounts false with no request limit: only the block and the refusals hold a request
     * @return int seconds until the window, the block and, when they pause the caller, the refusals end
     */
    public function getWaitSeconds(
        string $apiKeyHash,
        string $route,
        bool $pausedByRefusals,
        bool $windowCounts = true
    ): int {
        $connection = $this->getConnection();
        $pauseEnds = ['COALESCE(TIMESTAMPDIFF(SECOND, NOW(), blocked_until), 0)'];
        if ($pausedByRefusals) {
            $pauseEnds[] = 'COALESCE(TIMESTAMPDIFF(SECOND, NOW(), key_refused_until), 0)';
            $routeRefusal = $this->selectRouteRefusal(
                $route,
                new Expression('TIMESTAMPDIFF(SECOND, NOW(), route_refusal.refused_until)')
            );
            $pauseEnds[] = 'COALESCE((' . $routeRefusal . '), 0)';
        }
        $windowEnd = $windowCounts
            ? sprintf(
                'IF(%s, 0, TIMESTAMPDIFF(SECOND, NOW(), window_started_at + INTERVAL %d SECOND))',
                sprintf(self::WINDOW_EXPIRED, self::WINDOW_SECONDS),
                self::WINDOW_SECONDS
            )
            : '0';
        $seconds = $connection->fetchOne(
            sprintf('SELECT GREATEST(0, %s, %s)', $windowEnd, implode(', ', $pauseEnds))
            . ' FROM ' . $connection->quoteIdentifier($this->getMainTable())
            . ' WHERE api_key_hash = ?',
            [$apiKeyHash]
        );
        if ($seconds === false) {
            return 0;
        }

        return (int) $seconds;
    }

    /**
     * With no request limit nothing is counted: a request waits only for a 429 block or, when they pause the
     * caller, a refusal.
     *
     * @param string $apiKeyHash
     * @param string $route
     * @param bool $pausedByRefusals false for interactive calls, which a refusal never pauses
     * @return bool
     */
    public function isPaused(string $apiKeyHash, string $route, bool $pausedByRefusals): bool
    {
        $connection = $this->getConnection();
        $pause = ['(blocked_until IS NOT NULL AND blocked_until > NOW())'];
        if ($pausedByRefusals) {
            $pause[] = '(key_refused_until IS NOT NULL AND key_refused_until > NOW())';
            $routeRefusal = $this->selectRouteRefusal($route, new Expression('1'))
                ->where('route_refusal.refused_until > NOW()');
            $pause[] = 'EXISTS (' . $routeRefusal . ')';
        }

        $paused = $connection->fetchOne(
            'SELECT 1 FROM ' . $connection->quoteIdentifier($this->getMainTable())
            . ' WHERE api_key_hash = ? AND (' . implode(' OR ', $pause) . ')',
            [$apiKeyHash]
        );

        return $paused !== false;
    }

    /**
     * Stamp a key check unless one was stamped less than $intervalSeconds ago, in one statement.
     *
     * @param string $checkHash
     * @param int $intervalSeconds
     * @return bool true when this process may check the key now
     */
    public function claimCheck(string $checkHash, int $intervalSeconds): bool
    {
        $connection = $this->getConnection();
        $this->ensureRow($checkHash);

        $affected = $connection->update(
            $this->getMainTable(),
            [self::LAST_CHECK_AT => new Expression('NOW()')],
            [
                'api_key_hash = ?' => $checkHash,
                $connection->quoteInto(
                    '(last_check_at IS NULL OR last_check_at <= NOW() - INTERVAL ? SECOND)',
                    $intervalSeconds
                ),
            ]
        );

        return $affected === 1;
    }

    /**
     * @param string $checkHash
     * @param int $intervalSeconds
     * @return int seconds until the key may be checked again
     */
    public function getCheckWaitSeconds(string $checkHash, int $intervalSeconds): int
    {
        $connection = $this->getConnection();
        $seconds = $connection->fetchOne(
            $connection->select()
                ->from(
                    $this->getMainTable(),
                    new Expression($connection->quoteInto(
                        'GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), last_check_at + INTERVAL ? SECOND))',
                        $intervalSeconds
                    ))
                )
                ->where('api_key_hash = ?', $checkHash)
        );

        return (int) $seconds;
    }

    /**
     * Delete refusals expired for over a day and key rows not updated for a week, one DELETE each.
     *
     * @return void
     */
    public function deleteStaleRows(): void
    {
        $connection = $this->getConnection();
        $connection->delete(
            $this->getTable(self::REFUSAL_TABLE),
            [sprintf('refused_until < NOW() - INTERVAL %d DAY', self::EXPIRED_REFUSAL_RETENTION_DAYS)]
        );
        $connection->delete(
            $this->getMainTable(),
            [sprintf('updated_at < NOW() - INTERVAL %d DAY', self::IDLE_ROW_RETENTION_DAYS)]
        );
    }

    /**
     * The route's refusal of the main table's key, as a correlated subquery; the route is a bound value.
     *
     * @param string $route
     * @param Expression $column
     * @return Select
     */
    private function selectRouteRefusal(string $route, Expression $column): Select
    {
        $connection = $this->getConnection();

        return $connection->select()
            ->from(['route_refusal' => $this->getTable(self::REFUSAL_TABLE)], [$column])
            ->where(
                'route_refusal.api_key_hash = '
                . $connection->quoteIdentifier($this->getMainTable()) . '.api_key_hash'
            )
            ->where('route_refusal.route = ?', $route);
    }

    /**
     * Push a block or the key-wide refusal forward; a shorter value never shortens one already recorded.
     *
     * @param string $apiKeyHash
     * @param string $column BLOCKED_UNTIL or KEY_REFUSED_UNTIL
     * @param int $seconds
     * @return void
     */
    private function extendUntil(string $apiKeyHash, string $column, int $seconds): void
    {
        $connection = $this->getConnection();
        $this->ensureRow($apiKeyHash);

        $connection->update(
            $this->getMainTable(),
            [
                $column => new Expression($connection->quoteInto(
                    sprintf(
                        'GREATEST(COALESCE(%1$s, NOW()), NOW() + INTERVAL ? SECOND)',
                        $connection->quoteIdentifier($column)
                    ),
                    $this->boundSeconds($seconds)
                )),
            ],
            ['api_key_hash = ?' => $apiKeyHash]
        );
    }

    /**
     * @param int $seconds
     * @return int
     */
    private function boundSeconds(int $seconds): int
    {
        return max(1, min($seconds, self::MAX_BLOCK_SECONDS));
    }

    /**
     * Create the key's row, or touch it so the idle-row purge cannot delete it before the caller's UPDATE.
     *
     * @param string $apiKeyHash
     * @return void
     */
    private function ensureRow(string $apiKeyHash): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            ['api_key_hash' => $apiKeyHash],
            ['updated_at' => new Expression('NOW()')]
        );
    }
}
