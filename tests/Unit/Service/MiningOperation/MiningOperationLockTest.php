<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationStatus;
use App\Service\MiningOperation\MiningOperationSettlement;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningOperationSettlement::class)]
#[Group('issue-265')]
final class MiningOperationLockTest extends TestCase
{
    use BuildsSpodumainOperation;

    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testSettlingLocksTheOperationRowInsideATransactionAndRollsBackWhenRefused(): void
    {
        $operation = $this->spodumainOperation(MiningOperationStatus::Planned);
        $journal = [];
        $this->entityManager->method('beginTransaction')->willReturnCallback(static function () use (&$journal): void {
            $journal[] = 'begin';
        });
        $this->entityManager->method('refresh')->willReturnCallback(static function (MiningOperation $refreshed, LockMode|int|null $lockMode = null) use (&$journal, $operation): void {
            $journal[] = [$refreshed === $operation, $lockMode === LockMode::PESSIMISTIC_WRITE];
        });
        $this->entityManager->method('commit')->willReturnCallback(static function () use (&$journal): void {
            $journal[] = 'commit';
        });
        $this->entityManager->method('rollback')->willReturnCallback(static function () use (&$journal): void {
            $journal[] = 'rollback';
        });

        try {
            (new MiningOperationSettlement($this->entityManager, new MockClock('2026-11-05T22:00:00+00:00')))->settle($operation, $this->aria);
            self::fail('A planned operation cannot be settled');
        } catch (BadRequestHttpException) {
        }

        self::assertSame(['begin', [true, true], 'rollback'], $journal);
    }
}
