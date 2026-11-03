<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\UpdateMiningOperationValuationInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateMiningOperationValuationInput::class)]
#[Group('issue-264')]
final class UpdateMiningOperationValuationInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    private const string CYRA_MEMBER_ID = '0f6c1d2e-3a4b-4c5d-8e7f-9a0b1c2d3e4f';

    public function testPatchWithOnlySomeFieldsIsValid(): void
    {
        self::assertSame(
            [[], []],
            [
                $this->violatedFieldsOf(new UpdateMiningOperationValuationInput()),
                $this->violatedFieldsOf($this->patching(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => self::CYRA_MEMBER_ID])),
            ],
        );
    }

    public function testEveryFieldWithinItsBoundsIsValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->patching([
            'valuationForm' => 'reprocessed',
            'valuationMarket' => 'structure',
            'valuationSide' => 'buy',
            'structureId' => 1_000_000_000_001,
            'structureName' => 'Refinery B-1',
            'reprocessYield' => 1.0,
            'boosterCutPercent' => 100,
            'corporationCutPercent' => 0,
            'boosterMemberId' => self::CYRA_MEMBER_ID,
        ])));
    }

    public function testFieldsSetBackToNullAreValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->patching([
            'structureId' => null,
            'structureName' => null,
            'reprocessYield' => null,
            'boosterMemberId' => null,
        ])));
    }

    public function testReprocessingYieldOutsideZeroExcludedToOneIsRejectedOnTheYield(): void
    {
        self::assertSame(
            [['reprocessYield'], ['reprocessYield'], []],
            [
                $this->violatedFieldsOf($this->patching(['reprocessYield' => 0.0])),
                $this->violatedFieldsOf($this->patching(['reprocessYield' => 1.5])),
                $this->violatedFieldsOf($this->patching(['reprocessYield' => 0.72358])),
            ],
        );
    }

    public function testCutsOutsideZeroToOneHundredArePercentViolations(): void
    {
        self::assertSame(
            [['boosterCutPercent'], ['boosterCutPercent'], ['corporationCutPercent'], ['corporationCutPercent']],
            [
                $this->violatedFieldsOf($this->patching(['boosterCutPercent' => 101])),
                $this->violatedFieldsOf($this->patching(['boosterCutPercent' => -1])),
                $this->violatedFieldsOf($this->patching(['corporationCutPercent' => 101])),
                $this->violatedFieldsOf($this->patching(['corporationCutPercent' => -1])),
            ],
        );
    }

    public function testUnknownFormMarketOrSideIsRejectedOnItsField(): void
    {
        self::assertSame(
            [['valuationForm'], ['valuationMarket'], ['valuationSide']],
            [
                $this->violatedFieldsOf($this->patching(['valuationForm' => 'best'])),
                $this->violatedFieldsOf($this->patching(['valuationMarket' => 'amarr'])),
                $this->violatedFieldsOf($this->patching(['valuationSide' => 'mid'])),
            ],
        );
    }

    public function testStructureMustBeAPositiveIdWithANameOfAtMost255Characters(): void
    {
        self::assertSame(
            [['structureId'], ['structureName'], ['structureName'], []],
            [
                $this->violatedFieldsOf($this->patching(['structureId' => 0])),
                $this->violatedFieldsOf($this->patching(['structureName' => ''])),
                $this->violatedFieldsOf($this->patching(['structureName' => str_repeat('R', 256)])),
                $this->violatedFieldsOf($this->patching(['structureName' => str_repeat('R', 255)])),
            ],
        );
    }

    public function testBoosterMemberIdMustBeAUuid(): void
    {
        self::assertSame(['boosterMemberId'], $this->violatedFieldsOf($this->patching(['boosterMemberId' => 'abc'])));
    }

    private function patching(array $fields): UpdateMiningOperationValuationInput
    {
        $input = new UpdateMiningOperationValuationInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $input;
    }
}
