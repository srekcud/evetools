<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MiningOperationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationStatus::class)]
#[Group('issue-262')]
#[Group('issue-264')]
#[Group('issue-265')]
final class MiningOperationStatusTest extends TestCase
{
    public function testEveryStatusButSharesSettledAndCompletedIsAnOpenOperation(): void
    {
        self::assertSame(
            ['planned' => true, 'in_progress' => true, 'closing' => true, 'shares' => false, 'settled' => false, 'completed' => false],
            $this->byStatus(static fn (MiningOperationStatus $status): bool => $status->isOpen()),
        );
    }

    public function testOnlyPlannedAndInProgressOperationsAcceptParticipants(): void
    {
        self::assertSame(
            ['planned' => true, 'in_progress' => true, 'closing' => false, 'shares' => false, 'settled' => false, 'completed' => false],
            $this->byStatus(static fn (MiningOperationStatus $status): bool => $status->acceptsParticipants()),
        );
    }

    public function testOnlySettledAndCompletedOperationsAreArchived(): void
    {
        self::assertSame(
            ['planned' => false, 'in_progress' => false, 'closing' => false, 'shares' => false, 'settled' => true, 'completed' => true],
            $this->byStatus(static fn (MiningOperationStatus $status): bool => $status->isArchived()),
        );
    }

    private function byStatus(\Closure $rule): array
    {
        $outcomes = [];
        foreach (MiningOperationStatus::cases() as $status) {
            $outcomes[$status->value] = $rule($status);
        }

        return $outcomes;
    }
}
