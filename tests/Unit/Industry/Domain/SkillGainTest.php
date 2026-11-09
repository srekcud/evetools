<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\InventionChance;
use App\Industry\Domain\SkillGain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillGain::class)]
#[Group('issue-359')]
final class SkillGainTest extends TestCase
{
    private const float HOURS_TOLERANCE = 0.0001;
    private const float NEUTRAL_BASE_PROBABILITY = 0.01;

    public function testALevelOfATimeSkillSavesItsShareOfTheHoursRemainingAtTheCurrentLevel(): void
    {
        $this->assertEqualsWithDelta(4.3478, SkillGain::timeSavedHours(100.0, 2, 0.04), self::HOURS_TOLERANCE);
    }

    public function testTheFirstLevelOfATimeSkillSavesItsReductionOnTheWholeHours(): void
    {
        $this->assertEqualsWithDelta(2.0, SkillGain::timeSavedHours(50.0, 0, 0.04), self::HOURS_TOLERANCE);
    }

    public function testNoHoursSaveNothing(): void
    {
        $this->assertSame(0.0, SkillGain::timeSavedHours(0.0, 3, 0.03));
    }

    public function testTheLastLevelOfATimeSkillStillSavesHours(): void
    {
        $this->assertEqualsWithDelta(100 * 0.03 / (1 - 0.03 * 4), SkillGain::timeSavedHours(100.0, 4, 0.03), self::HOURS_TOLERANCE);
    }

    /**
     * @return iterable<string, array{float, int, float}>
     */
    public static function invalidTimeSkillInputs(): iterable
    {
        yield 'negative hours' => [-1.0, 2, 0.04];
        yield 'negative level' => [10.0, -1, 0.04];
        yield 'level five has no next level' => [10.0, 5, 0.04];
        yield 'zero reduction' => [10.0, 2, 0.0];
    }

    #[DataProvider('invalidTimeSkillInputs')]
    public function testRefusesTimeSkillInputsThatCannotBeRaised(float $hours, int $level, float $reduction): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillGain::timeSavedHours($hours, $level, $reduction);
    }

    public function testARaisedInventionChanceSavesTheHoursOfTheAttemptsItAvoids(): void
    {
        $saved = SkillGain::inventionSavedHours(10.0, $this->chance(3, 3, 2), $this->chance(3, 4, 2));

        $this->assertEqualsWithDelta(0.25974, $saved, self::HOURS_TOLERANCE);
    }

    public function testAnEncryptionLevelIsWorthLessThanAScienceLevel(): void
    {
        $saved = SkillGain::inventionSavedHours(10.0, $this->chance(3, 3, 2), $this->chance(3, 3, 3));

        $this->assertEqualsWithDelta(0.19608, $saved, self::HOURS_TOLERANCE);
    }

    public function testAnUnchangedChanceSavesNothing(): void
    {
        $this->assertSame(0.0, SkillGain::inventionSavedHours(10.0, $this->chance(3, 3, 2), $this->chance(3, 3, 2)));
    }

    public function testRefusesNegativeInventionHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillGain::inventionSavedHours(-1.0, $this->chance(1, 1, 1), $this->chance(1, 2, 1));
    }

    public function testAnExtraSlotWorksLikeTheAverageSlot(): void
    {
        $this->assertSame(60.0, SkillGain::averageSlotHours(120.0, 2));
    }

    public function testTheOnlySlotOfACategoryIsTheAverageSlot(): void
    {
        $this->assertSame(40.0, SkillGain::averageSlotHours(40.0, 1));
    }

    public function testAnExtraSlotOfAnUnusedCategoryEarnsNothing(): void
    {
        $this->assertSame(0.0, SkillGain::averageSlotHours(0.0, 3));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function invalidSlotInputs(): iterable
    {
        yield 'no slot' => [10.0, 0];
        yield 'negative slots' => [10.0, -1];
        yield 'negative hours' => [-1.0, 2];
    }

    #[DataProvider('invalidSlotInputs')]
    public function testRefusesSlotInputsWithoutAnAverageSlot(float $hours, int $slots): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillGain::averageSlotHours($hours, $slots);
    }

    private function chance(int $firstScience, int $secondScience, int $encryption): InventionChance
    {
        return InventionChance::of(self::NEUTRAL_BASE_PROBABILITY, $firstScience, $secondScience, $encryption, null);
    }
}
