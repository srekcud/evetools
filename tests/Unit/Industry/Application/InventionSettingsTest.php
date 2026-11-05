<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\InventionSettings;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionSettings::class)]
final class InventionSettingsTest extends TestCase
{
    private const float BASE_PROBABILITY = 0.34;

    public function testTheChanceUsesTheSkillsAndTheDecryptor(): void
    {
        $settings = new InventionSettings(5, 4, 3, new Decryptor(34205, new Multiplier(1.1), 0, 3, 6));

        $this->assertEqualsWithDelta(self::BASE_PROBABILITY * (1 + 9 / 30 + 3 / 40) * 1.1, $settings->chanceOf(self::BASE_PROBABILITY)?->value, 1e-12);
    }

    public function testTheSkillsAreKnownWhenEveryLevelIsKnown(): void
    {
        $this->assertTrue((new InventionSettings(0, 0, 0))->skillsAreKnown());
    }

    /**
     * @return iterable<string, array{?int, ?int, ?int}>
     */
    public static function settingsWithAnUnknownLevel(): iterable
    {
        yield 'first science' => [null, 4, 4];
        yield 'second science' => [4, null, 4];
        yield 'encryption' => [4, 4, null];
        yield 'all' => [null, null, null];
    }

    #[DataProvider('settingsWithAnUnknownLevel')]
    public function testASettingWithAnUnknownLevelHasUnknownSkillsAndNoChance(?int $firstScience, ?int $secondScience, ?int $encryption): void
    {
        $settings = new InventionSettings($firstScience, $secondScience, $encryption);

        $this->assertFalse($settings->skillsAreKnown());
        $this->assertNull($settings->chanceOf(self::BASE_PROBABILITY));
    }
}
