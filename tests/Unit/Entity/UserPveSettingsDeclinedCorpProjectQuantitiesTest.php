<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\UserPveSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPveSettings::class)]
#[Group('issue-243')]
final class UserPveSettingsDeclinedCorpProjectQuantitiesTest extends TestCase
{
    private const TRITANIUM_DRIVE_PROJECT_ID = 'a1b2c3d4-project';
    private const VELDSPAR_HAUL_PROJECT_ID = 'e5f6a7b8-project';
    private const PILOT_ONE_ID = 2112000001;
    private const PILOT_TWO_ID = 2112000002;

    public function testNewSettingsHaveNoDeclinedCorpProjectQuantity(): void
    {
        $settings = new UserPveSettings();

        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame(0, $settings->declinedCorpProjectQuantityOf(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID));
    }

    public function testDeclinedQuantitiesOfTheSamePairAddUp(): void
    {
        $settings = new UserPveSettings();

        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID, 600);
        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID, 300);

        $this->assertSame(900, $settings->declinedCorpProjectQuantityOf(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID));
        $this->assertSame(['a1b2c3d4-project:2112000001' => 900], $this->declinedCorpProjectQuantitiesOf($settings));
    }

    public function testDeclinedQuantitiesAreKeptPerProjectAndCharacterPair(): void
    {
        $settings = new UserPveSettings();

        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID, 600);
        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_TWO_ID, 250);
        $settings->addDeclinedCorpProjectQuantity(self::VELDSPAR_HAUL_PROJECT_ID, self::PILOT_ONE_ID, 75);

        $this->assertSame(
            [
                'a1b2c3d4-project:2112000001' => 600,
                'a1b2c3d4-project:2112000002' => 250,
                'e5f6a7b8-project:2112000001' => 75,
            ],
            $this->declinedCorpProjectQuantitiesOf($settings),
        );
        $this->assertSame(
            [600, 250, 75, 0],
            [
                $settings->declinedCorpProjectQuantityOf(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID),
                $settings->declinedCorpProjectQuantityOf(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_TWO_ID),
                $settings->declinedCorpProjectQuantityOf(self::VELDSPAR_HAUL_PROJECT_ID, self::PILOT_ONE_ID),
                $settings->declinedCorpProjectQuantityOf(self::VELDSPAR_HAUL_PROJECT_ID, self::PILOT_TWO_ID),
            ],
        );
    }

    public function testResetOfTheDeclinedEmptiesTheDeclinedCorpProjectQuantities(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID, 600);
        $settings->addDeclinedCorpProjectQuantity(self::VELDSPAR_HAUL_PROJECT_ID, self::PILOT_TWO_ID, 75);

        $settings->clearDeclinedExcept();

        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame(0, $settings->declinedCorpProjectQuantityOf(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID));
    }

    public function testResetKeepingTheDisplayedSalesStillEmptiesTheDeclinedCorpProjectQuantities(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedContractId(720001);
        $settings->addDeclinedLootSaleTransactionId(8200017);
        $settings->addDeclinedCorpProjectQuantity(self::TRITANIUM_DRIVE_PROJECT_ID, self::PILOT_ONE_ID, 600);

        $settings->clearDeclinedExcept([720001], [8200017, -abs(crc32('a1b2c3d4-project_2112000001_1600'))]);

        $this->assertSame([], $this->declinedCorpProjectQuantitiesOf($settings));
        $this->assertSame([720001], $settings->getDeclinedContractIds());
        $this->assertSame([8200017], $settings->getDeclinedLootSaleTransactionIds());
    }

    private function declinedCorpProjectQuantitiesOf(UserPveSettings $settings): array
    {
        return (new \ReflectionProperty(UserPveSettings::class, 'declinedCorpProjectQuantities'))->getValue($settings);
    }
}
