<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Order;

use Centraliza\Connector\Entity\Names;
use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * The order with everything GET /V1/orders/:id answers, plus its invoices,
 * shipments (with tracking numbers) and credit memos, arranged by subject.
 */
class OrderReader implements StreamReaderInterface
{
    /** Parts of the order that keep their own place in the record. */
    private const PARTS = ['items', 'billing_address', 'payment', 'status_histories', 'extension_attributes'];
    private const FIRST = ['entity_id', 'increment_id', 'state', 'status', 'store_id', 'created_at', 'updated_at'];

    public function __construct(
        private readonly OrderRepositoryInterfaceFactory $ordersFactory,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly CreditmemoRepositoryInterface $creditmemos,
        private readonly SearchCriteriaBuilderFactory $criteriaFactory,
        private readonly ResourceConnection $resource,
        private readonly ServiceRecord $serviceRecord,
        private readonly Names $names
    ) {
    }

    public function read(array $key): ?array
    {
        $orderId = (int)$key['order_id'];
        try {
            // A new repository each time: it keeps every order it loaded and has no way to forget one.
            $order = $this->ordersFactory->create()->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $rest = $this->serviceRecord->toArray($order, OrderRepositoryInterface::class, 'get');
        $extension = (array)($rest['extension_attributes'] ?? []);

        $customer = $currency = $totals = $other = [];
        foreach (array_diff_key($rest, array_flip(array_merge(self::PARTS, self::FIRST))) as $field => $value) {
            if (str_starts_with($field, 'customer_')) {
                $customer[substr($field, strlen('customer_'))] = $value;
            } elseif (preg_match('/_currency_code$|_to_[a-z]+_rate$/', $field) === 1) {
                $currency[$field] = $value;
            } elseif (is_numeric($value)
                && preg_match('/total|amount|_paid|_refunded|_invoiced|_canceled|_due|adjustment/', $field) === 1
            ) {
                $totals[$field] = $value;
            } else {
                $other[$field] = $value;
            }
        }
        if (isset($customer['group_id'])) {
            $customer['group'] = $this->names->customerGroup((int)$customer['group_id']);
            unset($customer['group_id']);
        }
        $status = (string)($rest['status'] ?? '');

        $record = [
            'id' => $rest['entity_id'] ?? null,
            'increment_id' => $rest['increment_id'] ?? null,
            'state' => $rest['state'] ?? null,
            'status' => ['value' => $status, 'label' => $this->statusLabel($status, (int)($rest['store_id'] ?? 0))],
            'store' => $this->names->store((int)($rest['store_id'] ?? 0)),
            'created_at' => $rest['created_at'] ?? null,
            'updated_at' => $rest['updated_at'] ?? null,
        ] + $other + [
            'customer' => $customer,
            'currency' => $currency,
            'totals' => $totals,
            'items' => $rest['items'] ?? [],
            'billing_address' => $rest['billing_address'] ?? null,
            'shipping_assignments' => $extension['shipping_assignments'] ?? [],
            'payment' => $rest['payment'] ?? null,
            'status_histories' => $rest['status_histories'] ?? [],
            'invoices' => $this->documents($this->invoices, InvoiceRepositoryInterface::class, $orderId),
            'shipments' => $this->documents($this->shipments, ShipmentRepositoryInterface::class, $orderId),
            'creditmemos' => $this->documents($this->creditmemos, CreditmemoRepositoryInterface::class, $orderId),
        ];
        unset($extension['shipping_assignments']);
        if ($extension !== []) {
            $record['extension_attributes'] = $extension;
        }

        return $record;
    }

    /**
     * The label the store view of the order gives the status, or the default one.
     * Read from the tables: the status configuration needs an area, and this
     * also runs where none is set.
     */
    private function statusLabel(string $status, int $storeId): ?string
    {
        $connection = $this->resource->getConnection('sales');
        $label = (string)$connection->fetchOne(
            $connection->select()
                ->from(['s' => $this->resource->getTableName('sales_order_status', 'sales')], [])
                ->joinLeft(
                    ['l' => $this->resource->getTableName('sales_order_status_label', 'sales')],
                    $connection->quoteInto('l.status = s.status AND l.store_id = ?', $storeId),
                    []
                )
                ->columns(['label' => $connection->getIfNullSql('l.label', 's.label')])
                ->where('s.status = ?', $status)
        );

        return $label === '' ? null : $label;
    }

    /**
     * @param InvoiceRepositoryInterface|ShipmentRepositoryInterface|CreditmemoRepositoryInterface $repository
     * @param class-string $interface
     * @return array<int, mixed>
     */
    private function documents(object $repository, string $interface, int $orderId): array
    {
        $criteria = $this->criteriaFactory->create()->addFilter('order_id', $orderId)->create();
        $found = $this->serviceRecord->toArray($repository->getList($criteria), $interface, 'getList');

        return array_values((array)($found['items'] ?? []));
    }
}
