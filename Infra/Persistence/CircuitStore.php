<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence;

use Centraliza\Connector\Core\Breaker\CircuitState;
use Centraliza\Connector\Core\Breaker\CircuitStatus;
use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Magento\Framework\App\ResourceConnection;

class CircuitStore implements CircuitStoreInterface
{
    private const TABLE = 'centraliza_connector_circuit';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function load(string $scope): CircuitState
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $row = $connection->fetchRow($connection->select()->from($table)->where('scope = ?', $scope));

        if ($row === false) {
            // The row has to exist for compareAndSet() to have something to match.
            $connection->insertOnDuplicate($table, ['scope' => $scope] + $this->columns(CircuitState::closed()), ['scope']);

            return CircuitState::closed();
        }

        return new CircuitState(
            CircuitStatus::tryFrom((string)$row['state']) ?? CircuitStatus::Closed,
            (int)$row['failures'],
            (int)$row['level'],
            (int)$row['open_until'],
            (int)$row['probe_until']
        );
    }

    public function compareAndSet(string $scope, CircuitState $expected, CircuitState $next): bool
    {
        $connection = $this->resource->getConnection();
        $where = ['scope = ?' => $scope];
        foreach ($this->columns($expected) as $column => $value) {
            $where[$column . ' = ?'] = $value;
        }

        return $connection->update($this->resource->getTableName(self::TABLE), $this->columns($next), $where) === 1;
    }

    /**
     * @return array<string, int|string>
     */
    private function columns(CircuitState $state): array
    {
        return [
            'state' => $state->status->value,
            'failures' => $state->failures,
            'level' => $state->level,
            'open_until' => $state->openUntil,
            'probe_until' => $state->probeUntil,
        ];
    }
}
