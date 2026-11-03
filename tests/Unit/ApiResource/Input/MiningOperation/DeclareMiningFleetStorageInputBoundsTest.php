<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\DeclareMiningFleetStorageInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclareMiningFleetStorageInput::class)]
#[Group('issue-266')]
final class DeclareMiningFleetStorageInputBoundsTest extends TestCase
{
    use ViolatedFieldsOf;

    #[DataProvider('partialLines')]
    public function testPartialLineIsValidOnlyWithinTheQuantityAndTypeBounds(int $typeId, int $quantity, array $expectedViolations): void
    {
        $input = new DeclareMiningFleetStorageInput();
        $input->delivery = 'partial';
        $input->quantities = [['typeId' => $typeId, 'quantity' => $quantity]];

        self::assertSame($expectedViolations, $this->violatedFieldsOf($input));
    }

    public static function partialLines(): iterable
    {
        yield 'one billion units' => [1230, 1_000_000_000, []];
        yield 'above one billion units' => [1230, 1_000_000_001, ['quantities']];
        yield 'largest 32-bit type id' => [2_147_483_647, 100, []];
        yield 'type id beyond 32 bits' => [2_147_483_648, 100, ['quantities']];
    }
}
