<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Entity\WebsiteScope;

/**
 * Reports a customer from how it was stored before and after a write.
 * Addresses are part of the customer: changing one reports the customer.
 */
class CustomerRecorder
{
    /** @var array<int, array{email: string, website_id: int, state: string}|null> stored state before the write in progress */
    private array $before = [];

    public function __construct(
        private readonly CustomerSnapshot $snapshot,
        private readonly WebsiteScope $scope,
        private readonly ChangeRecorderInterface $recorder
    ) {
    }

    /**
     * Call before the write, with the id the customer has by then (0 for a new one).
     */
    public function remember(int $customerId): void
    {
        if ($customerId > 0) {
            $this->before[$customerId] = $this->snapshot->of($customerId);
        }
    }

    /**
     * Call after a write that keeps the customer.
     */
    public function compare(int $customerId): void
    {
        $known = array_key_exists($customerId, $this->before);
        $before = $this->before[$customerId] ?? null;
        unset($this->before[$customerId]);

        $after = $this->snapshot->of($customerId);
        if ($after === null || !$this->scope->coversAny([$after['website_id']])) {
            return;
        }
        if ($before === null && $known) {
            $verb = EventVerb::Created;
        } elseif ($before !== null && $before['state'] === $after['state']) {
            return;
        } else {
            // Changed, or written by a path that was not seen before the write.
            $verb = EventVerb::Updated;
        }
        $this->recorder->record('customers', $verb, ['customer_id' => $customerId, 'email' => $after['email']]);
    }

    /**
     * Call after the write that created the customer.
     */
    public function created(int $customerId): void
    {
        $this->before[$customerId] = null;
        $this->compare($customerId);
    }

    /**
     * Call after the delete; the customer is reported as it was remembered.
     */
    public function deleted(int $customerId): void
    {
        $before = $this->before[$customerId] ?? null;
        unset($this->before[$customerId]);

        if ($before !== null && $this->scope->coversAny([$before['website_id']])) {
            $this->recorder->record('customers', EventVerb::Deleted, ['customer_id' => $customerId, 'email' => $before['email']]);
        }
    }
}
