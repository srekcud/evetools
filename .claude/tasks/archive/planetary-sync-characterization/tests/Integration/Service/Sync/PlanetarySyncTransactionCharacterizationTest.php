<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sync;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Exception\EsiApiException;
use App\Service\ESI\PlanetaryService;
use App\Service\Sync\PlanetarySyncService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetarySyncService::class)]
final class PlanetarySyncTransactionCharacterizationTest extends IntegrationTestCase
{
    private const int PLANET_AMAMAKE_IV = 40161469;
    private const int PLANET_VARD_II = 40161476;
    private const int BARREN_EXTRACTOR = 2848;
    private const int TEMPERATE_EXTRACTOR = 3060;
    private const int BASE_METALS = 2267;
    private const int HEAVY_METALS = 2272;
    private const int NOBLE_METALS = 2270;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int CARBON_COMPOUNDS = 2288;

    private PlanetaryService&Stub $planetaryService;
    private Character $character;
    private Uuid $amamakeColonyId;
    private Uuid $vardColonyId;
    private PlanetarySyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->character = $this->createCharacter(name: 'Selene Arkwright');
        $amamake = $this->colony(self::PLANET_AMAMAKE_IV, 'barren', 30002537, 'Amamake IV', [
            1020300000001 => self::HEAVY_METALS,
            1020300000002 => self::BASE_METALS,
        ]);
        $vard = $this->colony(self::PLANET_VARD_II, 'temperate', 30002538, 'Vard II', [
            1020300000003 => self::CARBON_COMPOUNDS,
        ]);
        $this->em->flush();
        $this->amamakeColonyId = $amamake->getId() ?? throw new \LogicException('Colony id not generated');
        $this->vardColonyId = $vard->getId() ?? throw new \LogicException('Colony id not generated');

        $this->planetaryService = $this->createStub(PlanetaryService::class);
        $this->planetaryService->method('fetchColonies')->willReturn([
            ['planet_id' => self::PLANET_AMAMAKE_IV, 'planet_type' => 'barren', 'solar_system_id' => 30002537, 'upgrade_level' => 4, 'num_pins' => 2, 'last_update' => '2026-10-10T18:42:07Z'],
            ['planet_id' => self::PLANET_VARD_II, 'planet_type' => 'temperate', 'solar_system_id' => 30002538, 'upgrade_level' => 3, 'num_pins' => 1, 'last_update' => '2026-10-09T09:12:44Z'],
        ]);

        $container = self::getContainer();
        $container->set(PlanetaryService::class, $this->planetaryService);
        $this->service = $container->get(PlanetarySyncService::class);
    }

    public function testSuccessfulSyncReplacesThePinsOfEveryColonyInTheDatabase(): void
    {
        $this->planetaryService->method('fetchColonyDetail')->willReturnCallback(
            static fn (Character $character, int $planetId): array => match ($planetId) {
                self::PLANET_AMAMAKE_IV => ['pins' => [
                    self::extractorPin(1020345678901, self::BARREN_EXTRACTOR, self::NOBLE_METALS),
                    self::extractorPin(1020345678902, self::BARREN_EXTRACTOR, self::BASE_METALS),
                ], 'routes' => []],
                self::PLANET_VARD_II => ['pins' => [
                    self::extractorPin(1020345678903, self::TEMPERATE_EXTRACTOR, self::AQUEOUS_LIQUIDS),
                ], 'routes' => []],
            },
        );

        $this->assertSame(2, $this->service->syncCharacterColonies($this->character));
        $this->em->clear();

        $this->assertSame([
            1020345678901 => self::NOBLE_METALS,
            1020345678902 => self::BASE_METALS,
        ], $this->storedPins($this->amamakeColonyId));
        $this->assertSame([1020345678903 => self::AQUEOUS_LIQUIDS], $this->storedPins($this->vardColonyId));
    }

    public function testResyncWithSamePinIdsReplacesPinsWithoutUniqueViolation(): void
    {
        $this->planetaryService->method('fetchColonyDetail')->willReturnCallback(
            static fn (Character $character, int $planetId): array => match ($planetId) {
                self::PLANET_AMAMAKE_IV => ['pins' => [
                    self::extractorPin(1020300000001, self::BARREN_EXTRACTOR, self::NOBLE_METALS),
                ], 'routes' => []],
                self::PLANET_VARD_II => ['pins' => [
                    self::extractorPin(1020300000003, self::TEMPERATE_EXTRACTOR, self::AQUEOUS_LIQUIDS),
                ], 'routes' => []],
            },
        );

        $this->assertSame(2, $this->service->syncCharacterColonies($this->character));
        $this->em->clear();

        $this->assertSame([1020300000001 => self::NOBLE_METALS], $this->storedPins($this->amamakeColonyId));
        $this->assertSame([1020300000003 => self::AQUEOUS_LIQUIDS], $this->storedPins($this->vardColonyId));
    }

    #[Group('pi-audit-5')]
    public function testEsiFailureOnSecondColonyCurrentlyLeavesFirstColonyWithoutAnyPinPiAudit5(): void
    {
        $failure = EsiApiException::fromResponse(502, 'Bad Gateway', '/characters/90000000/planets/40161476/');
        $this->planetaryService->method('fetchColonyDetail')->willReturnCallback(
            static fn (Character $character, int $planetId): array => match ($planetId) {
                self::PLANET_AMAMAKE_IV => ['pins' => [
                    self::extractorPin(1020345678901, self::BARREN_EXTRACTOR, self::NOBLE_METALS),
                    self::extractorPin(1020345678902, self::BARREN_EXTRACTOR, self::BASE_METALS),
                ], 'routes' => []],
                self::PLANET_VARD_II => throw $failure,
            },
        );

        $caught = null;
        try {
            $this->service->syncCharacterColonies($this->character);
        } catch (EsiApiException $exception) {
            $caught = $exception;
        }
        $this->em->clear();

        $this->assertSame($failure, $caught);
        $this->assertSame([], $this->storedPins($this->amamakeColonyId));
        $this->assertSame([1020300000003 => self::CARBON_COMPOUNDS], $this->storedPins($this->vardColonyId));
    }

    private function colony(int $planetId, string $planetType, int $solarSystemId, string $planetName, array $extractorProductsByPinId): PlanetaryColony
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($this->character)
            ->setPlanetId($planetId)
            ->setPlanetType($planetType)
            ->setSolarSystemId($solarSystemId)
            ->setPlanetName($planetName)
            ->setUpgradeLevel(2)
            ->setNumPins(count($extractorProductsByPinId))
            ->setLastUpdate(new \DateTimeImmutable('2026-09-30T10:00:00Z'));
        foreach ($extractorProductsByPinId as $pinId => $productTypeId) {
            $colony->addPin(
                (new PlanetaryPin())
                    ->setPinId($pinId)
                    ->setTypeId(self::BARREN_EXTRACTOR)
                    ->setExtractorProductTypeId($productTypeId)
                    ->setExpiryTime(new \DateTimeImmutable('2099-05-06T07:08:09Z')),
            );
        }
        $this->em->persist($colony);

        return $colony;
    }

    private function storedPins(Uuid $colonyId): array
    {
        $pins = $this->em->getRepository(PlanetaryPin::class)->findBy(['colony' => $colonyId], ['pinId' => 'ASC']);
        $extractorProductsByPinId = [];
        foreach ($pins as $pin) {
            $extractorProductsByPinId[$pin->getPinId()] = $pin->getExtractorProductTypeId();
        }

        return $extractorProductsByPinId;
    }

    private static function extractorPin(int $pinId, int $typeId, int $productTypeId): array
    {
        return [
            'pin_id' => $pinId,
            'type_id' => $typeId,
            'expiry_time' => '2099-05-06T07:08:09Z',
            'extractor_details' => ['product_type_id' => $productTypeId, 'cycle_time' => 1800, 'qty_per_cycle' => 5321],
        ];
    }
}
