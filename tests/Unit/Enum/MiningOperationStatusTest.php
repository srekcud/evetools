<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MiningOperationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationStatus::class)]
#[Group('issue-262')]
final class MiningOperationStatusTest extends TestCase
{
    public function testEveryStatusButCompletedIsAnOpenOperation(): void
    {
        self::assertSame(
            ['planned' => true, 'in_progress' => true, 'closing' => true, 'completed' => false],
            $this->byStatus(static fn (MiningOperationStatus $status): bool => $status->isOpen()),
        );
    }

    public function testOnlyPlannedAndInProgressOperationsAcceptParticipants(): void
    {
        self::assertSame(
            ['planned' => true, 'in_progress' => true, 'closing' => false, 'completed' => false],
            $this->byStatus(static fn (MiningOperationStatus $status): bool => $status->acceptsParticipants()),
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
