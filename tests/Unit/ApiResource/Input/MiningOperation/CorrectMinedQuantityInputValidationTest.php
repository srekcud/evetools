<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\CorrectMinedQuantityInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorrectMinedQuantityInput::class)]
#[Group('issue-263')]
final class CorrectMinedQuantityInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    private const int SPODUMAIN = 19;

    public function testNegativeQuantityIsRejectedOnTheQuantity(): void
    {
        self::assertSame(['quantity'], $this->violatedFieldsOf($this->correcting(self::SPODUMAIN, -1)));
    }

    public function testOnlyAPositiveTypeWithAQuantityOfZeroOrMoreIsValid(): void
    {
        self::assertSame(
            [[], [], ['typeId'], ['typeId', 'quantity']],
            [
                $this->violatedFieldsOf($this->correcting(self::SPODUMAIN, 0)),
                $this->violatedFieldsOf($this->correcting(self::SPODUMAIN, 20_000)),
                $this->violatedFieldsOf($this->correcting(0, 20_000)),
                $this->violatedFieldsOf($this->correcting(null, null)),
            ],
        );
    }

    public function testQuantityAboveOneBillionUnitsIsRejectedOnTheQuantity(): void
    {
        self::assertSame(
            [[], ['quantity']],
            [
                $this->violatedFieldsOf($this->correcting(self::SPODUMAIN, 1_000_000_000)),
                $this->violatedFieldsOf($this->correcting(self::SPODUMAIN, 1_000_000_001)),
            ],
        );
    }

    public function testTypeIdBeyondTheThirtyTwoBitColumnIsRejectedOnTheTypeId(): void
    {
        self::assertSame(
            [[], ['typeId']],
            [
                $this->violatedFieldsOf($this->correcting(2_147_483_647, 20_000)),
                $this->violatedFieldsOf($this->correcting(2_147_483_648, 20_000)),
            ],
        );
    }

    private function correcting(?int $typeId, ?int $quantity): CorrectMinedQuantityInput
    {
        $input = new CorrectMinedQuantityInput();
        $input->typeId = $typeId;
        $input->quantity = $quantity;

        return $input;
    }
}
