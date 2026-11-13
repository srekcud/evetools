<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\ContractSales;

use App\Constant\EveConstants;
use App\Entity\CachedStructure;
use App\Entity\Sde\MapSolarSystem;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserRepository;
use App\Service\Industry\ContractSales\PublicContractRegions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicContractRegions::class)]
#[Group('issue-334')]
final class PublicContractRegionsTest extends TestCase
{
    private const int CAPITAL_STRUCTURE = 1_046_664_001_931;
    private const int SAME_REGION_STRUCTURE = 1_046_664_001_932;
    private const int OTHER_REGION_STRUCTURE = 1_046_664_001_934;
    private const int UNKNOWN_STRUCTURE = 1_046_664_001_933;
    private const int CAPITAL_REGION = 10000030;
    private const int OTHER_REGION = 10000043;

    public function testTheForgeComesFirstThenTheRegionOfEachDistinctPreferredStructure(): void
    {
        $regions = $this->regionsFor([self::CAPITAL_STRUCTURE, self::OTHER_REGION_STRUCTURE]);

        $this->assertSame([EveConstants::THE_FORGE_REGION_ID, self::CAPITAL_REGION, self::OTHER_REGION], $regions->resolve()['regionIds']);
    }

    public function testRegionsSharedByTwoStructuresOrEqualToTheForgeAreListedOnce(): void
    {
        $regions = $this->regionsFor([self::CAPITAL_STRUCTURE, self::SAME_REGION_STRUCTURE]);

        $this->assertSame([EveConstants::THE_FORGE_REGION_ID, self::CAPITAL_REGION], $regions->resolve()['regionIds']);
    }

    public function testAStructureWhoseRegionIsUnknownIsReportedAndNeverReplacedByADefaultRegion(): void
    {
        $regions = $this->regionsFor([self::UNKNOWN_STRUCTURE, self::CAPITAL_STRUCTURE]);

        $resolved = $regions->resolve();

        $this->assertSame([EveConstants::THE_FORGE_REGION_ID, self::CAPITAL_REGION], $resolved['regionIds']);
        $this->assertSame([self::UNKNOWN_STRUCTURE], $resolved['unknownStructureIds']);
    }

    public function testWithoutAnyPreferredStructureOnlyTheForgeIsPolled(): void
    {
        $resolved = $this->regionsFor([])->resolve();

        $this->assertSame([EveConstants::THE_FORGE_REGION_ID], $resolved['regionIds']);
        $this->assertSame([], $resolved['unknownStructureIds']);
    }

    /**
     * @param list<int> $preferredStructureIds
     */
    private function regionsFor(array $preferredStructureIds): PublicContractRegions
    {
        $regionByStructure = [
            self::CAPITAL_STRUCTURE => self::CAPITAL_REGION,
            self::SAME_REGION_STRUCTURE => self::CAPITAL_REGION,
            self::OTHER_REGION_STRUCTURE => self::OTHER_REGION,
        ];

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findDistinctMarketStructureIds')->willReturn($preferredStructureIds);

        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            static function (int $structureId) use ($regionByStructure): ?CachedStructure {
                if (!isset($regionByStructure[$structureId])) {
                    return null;
                }

                return (new CachedStructure())->setStructureId($structureId)->setSolarSystemId($structureId);
            },
        );

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('find')->willReturnCallback(
            static fn (mixed $solarSystemId): ?MapSolarSystem => (new MapSolarSystem())->setRegionId($regionByStructure[$solarSystemId]),
        );

        return new PublicContractRegions($userRepository, $cachedStructureRepository, $solarSystemRepository);
    }
}
