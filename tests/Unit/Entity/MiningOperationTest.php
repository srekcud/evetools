<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperation::class)]
#[Group('issue-262')]
final class MiningOperationTest extends TestCase
{
    private const string JOIN_CODE_PATTERN = '/^[0-9a-f]{10}$/';
    private const string JOIN_CODE = '7f3a9c2e1b';

    public function testNewMiningOperationIsPlannedWithATenHexadecimalJoinCode(): void
    {
        $operation = new MiningOperation();

        self::assertSame(MiningOperationStatus::Planned, $operation->getStatus());
        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, $operation->getJoinCode());
    }

    public function testTwoNewMiningOperationsGetDifferentJoinCodes(): void
    {
        self::assertNotSame((new MiningOperation())->getJoinCode(), (new MiningOperation())->getJoinCode());
    }

    public function testRenewedJoinCodeIsANewTenHexadecimalCodeDifferentFromThePreviousOne(): void
    {
        $operation = (new MiningOperation())->setJoinCode(self::JOIN_CODE);

        $operation->renewJoinCode();

        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, $operation->getJoinCode());
        self::assertNotSame(self::JOIN_CODE, $operation->getJoinCode());
    }
}
