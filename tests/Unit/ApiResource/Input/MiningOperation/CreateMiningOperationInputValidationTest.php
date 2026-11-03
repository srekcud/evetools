<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateMiningOperationInput::class)]
#[Group('issue-262')]
final class CreateMiningOperationInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    private const string ARIA_SOLBERG_ID = '0d3e6b1a-5c47-4f28-9a61-2b8e7c4d9f10';
    private const string ARIA_II_ID = '6a9f2c4e-1b73-4d5a-8e02-7f3c9b1d6e24';

    public function testSpodumainOperationOfAriaIsValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->creation()));
    }

    public function testBlankLabelIsRejected(): void
    {
        self::assertSame(['label'], $this->violatedFieldsOf($this->creation(['label' => '   '])));
    }

    public function testLabelOfOneHundredAndOneCharactersIsRejected(): void
    {
        self::assertSame(['label'], $this->violatedFieldsOf($this->creation(['label' => str_repeat('a', 101)])));
    }

    public function testLabelOfOneHundredCharactersSurroundedBySpacesIsAccepted(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->creation(['label' => '  '.str_repeat('a', 100).'  '])));
    }

    public function testMissingPlannedStartIsRejected(): void
    {
        self::assertSame(['plannedStartAt'], $this->violatedFieldsOf($this->creation(['plannedStartAt' => null])));
    }

    public function testAllianceVisibilityIsRejected(): void
    {
        self::assertSame(['visibility'], $this->violatedFieldsOf($this->creation(['visibility' => 'alliance'])));
    }

    public function testWeightedTimeSplitModeIsRejected(): void
    {
        self::assertSame(['splitMode'], $this->violatedFieldsOf($this->creation(['splitMode' => 'weighted_time'])));
    }

    public function testEachForThemselvesSplitModeIsAccepted(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->creation(['splitMode' => 'each_for_themselves', 'visibility' => 'private'])));
    }

    public function testNoTickedCharacterIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->creation(['characterIds' => []])));
    }

    public function testSameCharacterTickedTwiceIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->creation(['characterIds' => [self::ARIA_II_ID, self::ARIA_II_ID]])));
    }

    public function testCharacterIdThatIsNotAUuidIsRejected(): void
    {
        self::assertSame(['characterIds'], $this->violatedFieldsOf($this->creation(['characterIds' => ['abc']])));
    }

    private function creation(array $overrides = []): CreateMiningOperationInput
    {
        $values = $overrides + [
            'label' => 'Anomalie Spodumain — 20:00',
            'plannedStartAt' => new \DateTimeImmutable('2026-11-05T20:00:00+00:00'),
            'visibility' => 'corporation',
            'splitMode' => 'value_prorata',
            'characterIds' => [self::ARIA_SOLBERG_ID, self::ARIA_II_ID],
        ];
        $input = new CreateMiningOperationInput();
        $input->label = $values['label'];
        $input->plannedStartAt = $values['plannedStartAt'];
        $input->visibility = $values['visibility'];
        $input->splitMode = $values['splitMode'];
        $input->characterIds = $values['characterIds'];

        return $input;
    }
}
