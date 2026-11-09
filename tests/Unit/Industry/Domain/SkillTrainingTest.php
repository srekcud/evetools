<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\SkillTraining;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillTraining::class)]
#[Group('issue-359')]
final class SkillTrainingTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function pointsToReachLevel(): iterable
    {
        yield 'rank 1 level 1' => [1, 1, 250];
        yield 'rank 1 level 2' => [1, 2, 1_415];
        yield 'rank 1 level 3' => [1, 3, 8_000];
        yield 'rank 1 level 4' => [1, 4, 45_255];
        yield 'rank 1 level 5' => [1, 5, 256_000];
        yield 'rank 3 level 5' => [3, 5, 768_000];
        yield 'rank 3 level 2' => [3, 2, 4_243];
    }

    #[DataProvider('pointsToReachLevel')]
    public function testTotalPointsToReachALevelFollowTheRankAndThePowerOfThirtyTwo(int $rank, int $level, int $expectedPoints): void
    {
        $this->assertSame($expectedPoints, SkillTraining::pointsForLevel($rank, $level));
    }

    public function testTheLastLevelOfARankOneSkillIsTrainedAtThirtyPointsPerMinute(): void
    {
        $this->assertSame(421_490, SkillTraining::secondsToNextLevel(1, 4, 20, 20));
    }

    public function testTheFirstLevelIsTrainedFromZeroPoints(): void
    {
        $this->assertSame(1_200, SkillTraining::secondsToNextLevel(3, 0, 27, 21));
    }

    public function testTheDurationIsRoundedUpToTheNextSecond(): void
    {
        $this->assertSame(2_292, SkillTraining::secondsToNextLevel(1, 1, 22, 17));
    }

    public function testAnAttributeOfExactlyOnePointIsAccepted(): void
    {
        $this->assertSame(10_000, SkillTraining::secondsToNextLevel(1, 0, 1, 1));
    }

    public function testADurationJustAboveAWholeSecondIsRoundedUp(): void
    {
        $this->assertSame(1_970, SkillTraining::secondsToNextLevel(1, 1, 21, 29));
    }

    public function testAHigherPrimaryAttributeShortensTheTraining(): void
    {
        $this->assertLessThan(
            SkillTraining::secondsToNextLevel(1, 3, 20, 20),
            SkillTraining::secondsToNextLevel(1, 3, 25, 20),
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function levelsOutsideOneToFive(): iterable
    {
        yield 'zero' => [1, 0];
        yield 'six' => [1, 6];
        yield 'negative' => [1, -1];
    }

    #[DataProvider('levelsOutsideOneToFive')]
    public function testRefusesALevelOutsideOneToFive(int $rank, int $level): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillTraining::pointsForLevel($rank, $level);
    }

    public function testRefusesARankBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillTraining::pointsForLevel(0, 3);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function invalidTrainingInputs(): iterable
    {
        yield 'already level five' => [1, 5, 20, 20];
        yield 'negative level' => [1, -1, 20, 20];
        yield 'zero primary attribute' => [1, 2, 0, 20];
        yield 'zero secondary attribute' => [1, 2, 20, 0];
        yield 'rank zero' => [0, 2, 20, 20];
    }

    #[DataProvider('invalidTrainingInputs')]
    public function testRefusesInputsThatHaveNoNextLevelOrNoTrainingSpeed(int $rank, int $currentLevel, int $primary, int $secondary): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillTraining::secondsToNextLevel($rank, $currentLevel, $primary, $secondary);
    }
}
