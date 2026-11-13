<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Service\Sync\CharacterSyncIsolation;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterSyncIsolation::class)]
#[Group('issue-798')]
final class CharacterSyncIsolationTest extends TestCase
{
    private Connection $connection;
    private ManagerRegistry $registry;

    private array $events = [];

    private bool $transactionActive = true;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(Connection::class);
        $this->connection->method('beginTransaction')->willReturnCallback(function (): void {
            $this->events[] = 'begin';
        });
        $this->connection->method('commit')->willReturnCallback(function (): void {
            $this->events[] = 'commit';
        });
        $this->connection->method('rollBack')->willReturnCallback(function (): void {
            $this->events[] = 'rollback';
        });
        $this->connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->transactionActive);

        $this->registry = $this->createStub(ManagerRegistry::class);
        $this->registry->method('resetManager')->willReturnCallback(function (): ObjectManager {
            $this->events[] = 'reset';

            return $this->createStub(ObjectManager::class);
        });
    }

    public function testCommitsTheTransactionWhenTheWorkSucceedsAndResetsNothing(): void
    {
        $this->isolation()->run(function (): void {
            $this->events[] = 'work';
        });

        self::assertSame(['begin', 'work', 'commit'], $this->events);
    }

    public function testRollsBackThenResetsTheManagerThenRethrowsTheOriginalExceptionWhenTheWorkFails(): void
    {
        $failure = new \RuntimeException('duplicate key value violates unique constraint');

        $thrown = $this->thrownBy(static fn () => throw $failure);

        self::assertSame($failure, $thrown);
        self::assertSame(['begin', 'rollback', 'reset'], $this->events);
    }

    public function testResetsTheManagerWithoutRollingBackWhenTheTransactionIsAlreadyClosed(): void
    {
        $this->transactionActive = false;

        $thrown = $this->thrownBy(static fn () => throw new \LogicException('closed by the unit of work'));

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertSame(['begin', 'reset'], $this->events);
    }

    public function testRunsEachWorkInItsOwnTransaction(): void
    {
        $isolation = $this->isolation();

        $this->thrownBy(static fn () => throw new \RuntimeException('first fails'), $isolation);
        $isolation->run(function (): void {
            $this->events[] = 'second work';
        });

        self::assertSame(['begin', 'rollback', 'reset', 'begin', 'second work', 'commit'], $this->events);
    }

    private function isolation(): CharacterSyncIsolation
    {
        return new CharacterSyncIsolation($this->connection, $this->registry);
    }

    private function thrownBy(\Closure $work, ?CharacterSyncIsolation $isolation = null): \Throwable
    {
        try {
            ($isolation ?? $this->isolation())->run($work);
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        self::fail('The isolation did not rethrow the failure of the work');
    }
}
