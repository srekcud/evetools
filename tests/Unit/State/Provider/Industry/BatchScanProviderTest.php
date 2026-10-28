<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\BatchProfitScannerService;
use App\State\Provider\Industry\BatchScanProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Issue #82: a scanned product whose cost is unknown (a material without price)
 * reaches the API with null cost and margins, the reason and the unpriced typeIds.
 * The scanner computation itself is covered by BatchProfitScannerServiceTest.
 */
#[CoversClass(BatchScanProvider::class)]
class BatchScanProviderTest extends TestCase
{
    private const int SLASHER_TYPE_ID = 585;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int ROBOTICS_TYPE_ID = 9848;

    public function testUnknownCostRowIsExposedWithNullCostAndMarginsAndItsReason(): void
    {
        $slasherWithUnknownCost = [
            'typeId' => self::SLASHER_TYPE_ID,
            'typeName' => 'Slasher',
            'groupName' => 'Frigate',
            'categoryLabel' => 'T1 Ships',
            'marginPercent' => null,
            'profitPerUnit' => null,
            'dailyVolume' => 50.0,
            'iskPerDay' => null,
            'materialCost' => null,
            'importCost' => 9.09,
            'exportCost' => 2500.0,
            'sellPrice' => 5000000.0,
            'meUsed' => 10,
            'activityType' => 'manufacturing',
            'isFactionBlueprint' => false,
            'bpcCostPerRun' => null,
            'hasAllSkills' => true,
            'missingSkillCount' => 0,
            'unknownReason' => 'missing_material_price',
            'missingPriceTypeIds' => [self::MORPHITE_TYPE_ID],
        ];

        $resources = $this->provideScanReturning([$slasherWithUnknownCost]);

        $this->assertCount(1, $resources);
        $slasher = $resources[0];
        $this->assertSame(self::SLASHER_TYPE_ID, $slasher->typeId);
        $this->assertNull($slasher->materialCost);
        $this->assertNull($slasher->marginPercent);
        $this->assertNull($slasher->profitPerUnit);
        $this->assertNull($slasher->iskPerDay);
        $this->assertSame('missing_material_price', $slasher->unknownReason);
        $this->assertSame([self::MORPHITE_TYPE_ID], $slasher->missingPriceTypeIds);
        $this->assertSame(9.09, $slasher->importCost);
        $this->assertSame(2500.0, $slasher->exportCost);
        $this->assertSame(5000000.0, $slasher->sellPrice);
    }

    #[Group('issue-255')]
    public function testUnknownTransportCostsAreExposedAsNullNextToTheKnownOne(): void
    {
        $slasherWithUnknownImportCost = [
            'typeId' => self::SLASHER_TYPE_ID,
            'typeName' => 'Slasher',
            'groupName' => 'Frigate',
            'categoryLabel' => 'T1 Ships',
            'marginPercent' => null,
            'profitPerUnit' => null,
            'dailyVolume' => 42.0,
            'iskPerDay' => null,
            'materialCost' => 412000.0,
            'importCost' => null,
            'exportCost' => 3000000.0,
            'sellPrice' => 4800000.0,
            'meUsed' => 10,
            'activityType' => 'manufacturing',
            'isFactionBlueprint' => false,
            'bpcCostPerRun' => null,
            'hasAllSkills' => true,
            'missingSkillCount' => 0,
            'unknownReason' => 'missing_volume',
            'missingPriceTypeIds' => [],
        ];
        $roboticsWithUnknownExportCost = [
            'typeId' => self::ROBOTICS_TYPE_ID,
            'typeName' => 'Robotics',
            'groupName' => 'Construction Components',
            'categoryLabel' => 'Components',
            'marginPercent' => null,
            'profitPerUnit' => null,
            'dailyVolume' => 310.0,
            'iskPerDay' => null,
            'materialCost' => 31750.0,
            'importCost' => 63.84,
            'exportCost' => null,
            'sellPrice' => 39500.0,
            'meUsed' => 10,
            'activityType' => 'manufacturing',
            'isFactionBlueprint' => false,
            'bpcCostPerRun' => null,
            'hasAllSkills' => true,
            'missingSkillCount' => 0,
            'unknownReason' => 'missing_volume',
            'missingPriceTypeIds' => [],
        ];

        $resources = $this->provideScanReturning([$slasherWithUnknownImportCost, $roboticsWithUnknownExportCost]);

        $this->assertSame(
            [[self::SLASHER_TYPE_ID, null, 3000000.0, 412000.0, 'missing_volume'], [self::ROBOTICS_TYPE_ID, 63.84, null, 31750.0, 'missing_volume']],
            array_map(
                static fn ($resource): array => [$resource->typeId, $resource->importCost, $resource->exportCost, $resource->materialCost, $resource->unknownReason],
                $resources,
            ),
        );
    }

    #[Group('issue-115')]
    public function testProductMissingFromTheSdeIsExposedWithANullTypeName(): void
    {
        $roboticsMissingFromTheSde = [
            'typeId' => self::ROBOTICS_TYPE_ID,
            'typeName' => null,
            'groupName' => 'Construction Components',
            'categoryLabel' => 'Components',
            'marginPercent' => 18.4,
            'profitPerUnit' => 7350.0,
            'dailyVolume' => 320.0,
            'iskPerDay' => 2352000.0,
            'materialCost' => 32600.0,
            'importCost' => 41.5,
            'exportCost' => 87.25,
            'sellPrice' => 40000.0,
            'meUsed' => 8,
            'activityType' => 'manufacturing',
            'isFactionBlueprint' => false,
            'bpcCostPerRun' => null,
            'hasAllSkills' => true,
            'missingSkillCount' => 0,
            'unknownReason' => null,
            'missingPriceTypeIds' => [],
        ];

        $resources = $this->provideScanReturning([$roboticsMissingFromTheSde]);

        $this->assertCount(1, $resources);
        $robotics = $resources[0];
        $this->assertSame(self::ROBOTICS_TYPE_ID, $robotics->typeId);
        $this->assertNull($robotics->typeName);
        $this->assertSame(18.4, $robotics->marginPercent);
        $this->assertSame(7350.0, $robotics->profitPerUnit);
        $this->assertSame(2352000.0, $robotics->iskPerDay);
        $this->assertSame(32600.0, $robotics->materialCost);
    }

    #[Group('issue-128')]
    public function testScannerReceivesTheFavoriteManufacturingAndReactionSystems(): void
    {
        $settings = $this->settings(30002510, 30000144);

        $this->assertSame([30002510, 30000144], $this->solarSystemsReceivedByTheScanner(new Request(), $settings));
    }

    #[Group('issue-128')]
    public function testScannerReceivesTheRequestedSystemForManufacturingAndReaction(): void
    {
        $settings = $this->settings(30002510, 30000144);

        $this->assertSame([30002187, 30002187], $this->solarSystemsReceivedByTheScanner(new Request(['solarSystemId' => '30002187']), $settings));
    }

    #[Group('issue-128')]
    public function testScannerReceivesTheManufacturingSystemForReactionWithoutFavoriteReactionSystem(): void
    {
        $settings = $this->settings(30002510, null);

        $this->assertSame([30002510, 30002510], $this->solarSystemsReceivedByTheScanner(new Request(), $settings));
    }

    #[Group('issue-128')]
    public function testScannerReceivesJitaForManufacturingAndReactionWithoutSettings(): void
    {
        $this->assertSame([EveConstants::JITA_SOLAR_SYSTEM_ID, EveConstants::JITA_SOLAR_SYSTEM_ID], $this->solarSystemsReceivedByTheScanner(new Request(), null));
    }

    private function settings(?int $favoriteManufacturingSystemId, ?int $favoriteReactionSystemId): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId($favoriteManufacturingSystemId)
            ->setFavoriteReactionSystemId($favoriteReactionSystemId);
    }

    /**
     * @return array{int, int|null}
     */
    private function solarSystemsReceivedByTheScanner(Request $request, ?IndustryUserSettings $settings): array
    {
        $receivedArguments = [];
        $scanner = $this->createStub(BatchProfitScannerService::class);
        $scanner->method('scan')->willReturnCallback(
            function (mixed ...$arguments) use (&$receivedArguments): array {
                $receivedArguments = $arguments;

                return [];
            },
        );

        $this->providerFor($scanner, $request, $settings)->provide(new GetCollection());

        return [$receivedArguments[5], $receivedArguments[10] ?? null];
    }

    private function providerFor(BatchProfitScannerService $scanner, Request $request, ?IndustryUserSettings $settings): BatchScanProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);

        return new BatchScanProvider($security, $requestStack, $scanner, $settingsRepository);
    }

    /**
     * @param list<array<string, mixed>> $scanRows
     * @return list<\App\ApiResource\Industry\BatchScanResultResource>
     */
    private function provideScanReturning(array $scanRows): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $scanner = $this->createStub(BatchProfitScannerService::class);
        $scanner->method('scan')->willReturn($scanRows);

        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        $provider = new BatchScanProvider($security, $requestStack, $scanner, $settingsRepository);

        return $provider->provide(new GetCollection());
    }
}
