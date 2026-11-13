<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Repository\CachedAssetRepository;
use App\Repository\TypePackagedVolumeRepository;
use App\Service\Assets\PackagedVolumeSyncService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PackagedVolumeSyncService::class)]
#[Group('issue-890')]
final class PackagedVolumeSyncServiceTest extends TestCase
{
    private const int RIFTER_TYPE_ID = 587;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PLAGUED_TYPE_ID = 890_001;
    private const int STATION_CONTAINER_TYPE_ID = 17_366;
    private const int STATION_VAULT_TYPE_ID = 17_367;
    private const int STATION_WAREHOUSE_TYPE_ID = 17_368;
    private const float RIFTER_PACKAGED_VOLUME = 2_500.0;
    private const float RIFTER_ASSEMBLED_VOLUME = 27_289.0;
    private const float TRITANIUM_VOLUME = 0.01;

    /** @var list<array{int, float}> */
    private array $stored = [];

    /** @var list<array<int, string>> */
    private array $requestedBatches = [];

    public function testStoresThePackagedVolumeRatherThanTheAssembledVolume(): void
    {
        $service = $this->serviceWith(
            [self::RIFTER_TYPE_ID],
            [self::RIFTER_TYPE_ID => ['packaged_volume' => self::RIFTER_PACKAGED_VOLUME, 'volume' => self::RIFTER_ASSEMBLED_VOLUME]],
        );

        $result = $service->sync();

        self::assertSame([[self::RIFTER_TYPE_ID, self::RIFTER_PACKAGED_VOLUME]], $this->stored);
        self::assertSame(1, $result->synced);
        self::assertSame(0, $result->failed);
        self::assertFalse($result->aborted);
    }

    public function testTypeWithoutRepackagedFormMovesAtItsVolume(): void
    {
        $service = $this->serviceWith(
            [self::TRITANIUM_TYPE_ID],
            [self::TRITANIUM_TYPE_ID => ['volume' => self::TRITANIUM_VOLUME]],
        );

        $service->sync();

        self::assertSame([[self::TRITANIUM_TYPE_ID, self::TRITANIUM_VOLUME]], $this->stored);
    }

    public function testTypeWithNeitherVolumeIsCountedAsFailedAndNotStored(): void
    {
        $service = $this->serviceWith(
            [self::RIFTER_TYPE_ID, self::PLAGUED_TYPE_ID],
            [
                self::RIFTER_TYPE_ID => ['packaged_volume' => self::RIFTER_PACKAGED_VOLUME],
                self::PLAGUED_TYPE_ID => [],
            ],
        );

        $result = $service->sync();

        self::assertSame([[self::RIFTER_TYPE_ID, self::RIFTER_PACKAGED_VOLUME]], $this->stored);
        self::assertSame(1, $result->synced);
        self::assertSame(1, $result->failed);
    }

    public function testNegativeVolumeIsCountedAsFailedAndNotStored(): void
    {
        $service = $this->serviceWith(
            [self::PLAGUED_TYPE_ID],
            [self::PLAGUED_TYPE_ID => ['packaged_volume' => -1.0]],
        );

        $result = $service->sync();

        self::assertSame([], $this->stored);
        self::assertSame(1, $result->failed);
    }

    public function testStationContainersAreNeverRequested(): void
    {
        $service = $this->serviceWith(
            [self::STATION_CONTAINER_TYPE_ID, self::RIFTER_TYPE_ID, self::STATION_VAULT_TYPE_ID, self::STATION_WAREHOUSE_TYPE_ID],
            [self::RIFTER_TYPE_ID => ['packaged_volume' => self::RIFTER_PACKAGED_VOLUME]],
        );

        $service->sync();

        self::assertSame(
            [[self::RIFTER_TYPE_ID => '/universe/types/587/']],
            $this->requestedBatches,
        );
    }

    public function testRequestsTwentyTypesPerBatch(): void
    {
        $typeIds = range(900_001, 900_045);
        $answers = array_fill_keys($typeIds, ['packaged_volume' => 1.0]);
        $service = $this->serviceWith($typeIds, $answers);

        $result = $service->sync();

        self::assertSame([20, 20, 5], array_map('count', $this->requestedBatches));
        self::assertSame(45, $result->synced);
    }

    public function testNoCachedAssetMeansNoEsiCall(): void
    {
        $service = $this->serviceWith([], []);

        $result = $service->sync();

        self::assertSame([], $this->requestedBatches);
        self::assertSame(0, $result->synced);
        self::assertSame(0, $result->failed);
        self::assertFalse($result->aborted);
    }

    public function testThreeConsecutiveBatchesWithoutAnswerAbortTheRun(): void
    {
        $typeIds = range(900_001, 900_100);
        $service = $this->serviceWith($typeIds, []);

        $result = $service->sync();

        self::assertCount(3, $this->requestedBatches);
        self::assertTrue($result->aborted);
        self::assertSame(60, $result->failed);
    }

    /**
     * @param list<int>                         $assetTypeIds
     * @param array<int, array<string, mixed>>  $answersByTypeId
     */
    private function serviceWith(array $assetTypeIds, array $answersByTypeId): PackagedVolumeSyncService
    {
        $assets = $this->createStub(CachedAssetRepository::class);
        $assets->method('findDistinctTypeIds')->willReturn($assetTypeIds);

        $esi = $this->createStub(EsiClient::class);
        $esi->method('getBatch')->willReturnCallback(function (array $endpoints) use ($answersByTypeId): array {
            $this->requestedBatches[] = $endpoints;

            return array_map(
                static fn (int $typeId): ?array => $answersByTypeId[$typeId] ?? null,
                array_combine(array_keys($endpoints), array_keys($endpoints)),
            );
        });

        $volumes = $this->createStub(TypePackagedVolumeRepository::class);
        $volumes->method('upsert')->willReturnCallback(function (int $typeId, float $packagedVolume): void {
            $this->stored[] = [$typeId, $packagedVolume];
        });

        return new PackagedVolumeSyncService($esi, $assets, $volumes, new NullLogger());
    }
}
