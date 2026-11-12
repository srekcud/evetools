<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ReadCountingCachePool implements CacheItemPoolInterface
{
    /** @var array<string, int> */
    private array $readsByKey = [];

    public function __construct(private readonly ArrayAdapter $inner = new ArrayAdapter())
    {
    }

    public function readsOf(string $key): int
    {
        return $this->readsByKey[$key] ?? 0;
    }

    public function resetReadCounts(): void
    {
        $this->readsByKey = [];
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->readsByKey[$key] = $this->readsOf($key) + 1;

        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            $this->readsByKey[$key] = $this->readsOf($key) + 1;
        }

        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
