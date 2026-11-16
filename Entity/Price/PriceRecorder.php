<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Price;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Service\SnapshotDiff;
use Centraliza\Connector\Entity\WebsiteScope;

class PriceRecorder
{
    public function __construct(
        private readonly PriceSnapshot $snapshot,
        private readonly SnapshotDiff $diff,
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope
    ) {
    }

    /**
     * @param string[] $skus
     * @return array<string, string>
     */
    public function stored(array $skus): array
    {
        return $this->snapshot->forSkus($skus);
    }

    /**
     * Reports the SKUs whose stored prices differ from how they were before.
     *
     * @param string[] $skus
     * @param array<string, string> $before
     */
    public function recordChanged(array $skus, array $before): void
    {
        // A price write never creates or removes the product, so every difference is an update.
        $changed = array_keys($this->diff->verbs($before, $this->snapshot->forSkus($skus)));
        if ($changed === []) {
            return;
        }
        $covered = $this->scope->coveredSkus(array_map('strval', $changed));
        foreach ($changed as $sku) {
            if (isset($covered[(string)$sku])) {
                $this->recorder->record('prices', EventVerb::Updated, ['sku' => (string)$sku]);
            }
        }
    }
}
