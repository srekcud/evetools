<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\InventionSettings;
use App\Industry\Application\InventionSkillLevels;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionSkillLevels::class)]
final class InventionSkillLevelsTest extends TestCase
{
    public function testNoSkillIsKnownAtLevelZero(): void
    {
        $levels = InventionSkillLevels::none();

        $this->assertSame([0, 0, 0], [$levels->firstScience, $levels->secondScience, $levels->encryption]);
        $this->assertTrue($levels->isKnown());
    }

    /**
     * @return iterable<string, array{?int, ?int, ?int}>
     */
    public static function levelsWithAnUnknownSkill(): iterable
    {
        yield 'first science' => [null, 4, 4];
        yield 'second science' => [4, null, 4];
        yield 'encryption' => [4, 4, null];
    }

    #[DataProvider('levelsWithAnUnknownSkill')]
    public function testASkillWithoutLevelMakesTheLevelsUnknown(?int $firstScience, ?int $secondScience, ?int $encryption): void
    {
        $this->assertFalse((new InventionSkillLevels($firstScience, $secondScience, $encryption))->isKnown());
    }

    public function testTheSettingsCarryTheLevelsAndTheDecryptor(): void
    {
        $decryptor = new Decryptor(34205, new Multiplier(1.1), 0, 3, 6);

        $settings = (new InventionSkillLevels(5, 4, 3))->settingsWith($decryptor);

        $this->assertEquals(new InventionSettings(5, 4, 3, $decryptor), $settings);
    }

    public function testTheSettingsKeepAnUnknownLevelUnknown(): void
    {
        $this->assertEquals(new InventionSettings(5, null, 3), (new InventionSkillLevels(5, null, 3))->settingsWith(null));
    }
}
