<?php

declare(strict_types=1);

namespace Centraliza\Connector\Block\Adminhtml;

use Centraliza\Connector\Infra\Queue\QueueDepth;
use Centraliza\Connector\Infra\Queue\QueuePublisher;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Phrase;

/**
 * Warns when a lane of the queue does not move: events wait and no consumer takes them.
 * With "show_broker_note" it also says that, with RabbitMQ, the events on the queue are only seen in the broker.
 */
class QueueNotice extends Template
{
    /**
     * @var array<string, array{waiting: int, oldest_seconds: int, stalled: bool}>|null|false false until read
     */
    private array|null|false $lanes = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly QueueDepth $queueDepth,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{lane: string, label: Phrase, consumer: string, waiting: int, minutes: int}>
     */
    public function getStalledLanes(): array
    {
        $stalled = [];
        foreach ($this->lanes() ?? [] as $lane => $state) {
            if (!$state['stalled']) {
                continue;
            }
            $stalled[] = [
                'lane' => $lane,
                'label' => $this->laneLabel($lane),
                'consumer' => QueuePublisher::TOPIC_PREFIX . $lane,
                'waiting' => $state['waiting'],
                'minutes' => intdiv($state['oldest_seconds'], 60),
            ];
        }

        return $stalled;
    }

    public function getStalledAfterMinutes(): int
    {
        return intdiv(QueueDepth::STALLED_AFTER_SECONDS, 60);
    }

    public function showBrokerNote(): bool
    {
        return (bool)$this->getData('show_broker_note') && $this->lanes() === null;
    }

    public function laneLabel(string $lane): Phrase
    {
        return match ($lane) {
            'realtime' => __('real time'),
            'bulk' => __('bulk'),
            default => __($lane),
        };
    }

    /**
     * @return array<string, array{waiting: int, oldest_seconds: int, stalled: bool}>|null
     */
    private function lanes(): ?array
    {
        if ($this->lanes === false) {
            $this->lanes = $this->queueDepth->lanes();
        }

        return $this->lanes;
    }
}
