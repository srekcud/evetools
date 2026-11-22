<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\UserSettingsResource;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\State\Provider\Industry\UserSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UserSettingsProvider::class)]
#[Group('issue-1286')]
final class UserSettingsProviderTest extends TestCase
{
    private const int DEFAULT_HUB_SYSTEM_ID = 30_000_772;
    private const int AMARR_SYSTEM_ID = 30_002_187;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;

    public function testUserWithoutSettingsRowGetsTheDefaultCourierHubAndBridgeRate(): void
    {
        $resource = $this->provide(null);

        self::assertEquals(
            [
                'favoriteManufacturingSystemId' => null,
                'favoriteManufacturingSystemName' => null,
                'favoriteReactionSystemId' => null,
                'favoriteReactionSystemName' => null,
                'exportCostPerM3' => 800.0,
                'courierHubSolarSystemId' => self::DEFAULT_HUB_SYSTEM_ID,
                'courierHubSolarSystemName' => 'C-J6MT',
                'jumpBridgeCostPerM3' => 0.0,
            ],
            get_object_vars($resource),
        );
    }

    public function testStoredCourierHubAndBridgeRateAreReturnedWithTheNameOfTheHubSystem(): void
    {
        $settings = (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId(self::PERIMETER_SYSTEM_ID)
            ->setExportCostPerM3(950.0)
            ->setCourierHubSolarSystemId(self::AMARR_SYSTEM_ID)
            ->setJumpBridgeCostPerM3(35.5);

        $resource = $this->provide($settings);

        self::assertSame(
            [950.0, self::AMARR_SYSTEM_ID, 'Amarr', 35.5, self::PERIMETER_SYSTEM_ID, 'Perimeter'],
            [
                $resource->exportCostPerM3,
                $resource->courierHubSolarSystemId,
                $resource->courierHubSolarSystemName,
                $resource->jumpBridgeCostPerM3,
                $resource->favoriteManufacturingSystemId,
                $resource->favoriteManufacturingSystemName,
            ],
        );
    }

    public function testZeroBridgeRateIsReturnedAsStoredAndNotReplacedByTheDefault(): void
    {
        $resource = $this->provide((new IndustryUserSettings())->setJumpBridgeCostPerM3(0.0));

        self::assertSame(0.0, $resource->jumpBridgeCostPerM3);
    }

    private function provide(?IndustryUserSettings $settings): UserSettingsResource
    {
        $user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);
        $solarSystemsInTheSde = [
            self::DEFAULT_HUB_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::DEFAULT_HUB_SYSTEM_ID)->setSolarSystemName('C-J6MT'),
            self::AMARR_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::AMARR_SYSTEM_ID)->setSolarSystemName('Amarr'),
            self::PERIMETER_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName('Perimeter'),
        ];
        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('find')
            ->willReturnCallback(static fn (mixed $solarSystemId): ?MapSolarSystem => $solarSystemsInTheSde[$solarSystemId] ?? null);

        return (new UserSettingsProvider($security, $settingsRepository, $solarSystemRepository))->provide(new Get());
    }
}
