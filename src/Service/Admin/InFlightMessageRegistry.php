<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;

class InFlightMessageRegistry
{
    private const RECEIVER_NAMES = ['async', 'on_demand', 'scheduler_default', 'failed'];
    private const KEY_PREFIX = 'in_flight_message.';
    private const ENTRY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    public function start(string $receiverName, string $messageClass): void
    {
        $item = $this->cache->getItem($this->keyOf($receiverName));
        $startedAt = $this->clock->now();
        $item->set(['messageClass' => $messageClass, 'startedAt' => $startedAt->getTimestamp()]);
        $item->expiresAt($startedAt->modify(sprintf('+%d seconds', self::ENTRY_TTL_SECONDS)));
        $this->cache->save($item);
    }

    public function finish(string $receiverName): void
    {
        $this->cache->deleteItem($this->keyOf($receiverName));
    }

    public function oldestRunningSeconds(): ?int
    {
        $oldestStart = null;

        foreach ($this->cache->getItems(array_map($this->keyOf(...), self::RECEIVER_NAMES)) as $item) {
            if (!$item->isHit()) {
                continue;
            }

            $startedAt = (int) $item->get()['startedAt'];
            $oldestStart = $oldestStart === null ? $startedAt : min($oldestStart, $startedAt);
        }

        return $oldestStart === null ? null : $this->clock->now()->getTimestamp() - $oldestStart;
    }

    private function keyOf(string $receiverName): string
    {
        if (!in_array($receiverName, self::RECEIVER_NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown receiver "%s"', $receiverName));
        }

        return self::KEY_PREFIX . $receiverName;
    }
}
