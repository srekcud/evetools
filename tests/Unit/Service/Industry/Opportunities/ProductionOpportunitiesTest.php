<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Opportunities;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\Sde\IndustryActivity;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\Opportunities\FreeSlots;
use App\Service\Industry\Opportunities\OpportunityMarket;
use App\Service\Industry\Opportunities\OpportunitySort;
use App\Service\Industry\Opportunities\ProductionOpportunities;
use App\Service\Industry\Opportunities\ProductionOpportunityRanker;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\Industry\SlotTrackerService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionOpportunities::class)]
#[Group('issue-356')]
final class ProductionOpportunitiesTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2_446;
    private const int HAMMERHEAD_BLUEPRINT_ID = 2_447;
    private const int HIGH_ENERGY_PHYSICS_SKILL_ID = 11_433;
    private const int INDUSTRY_SKILL_ID = 3_380;
    private const int CAPITAL_HUB_ID = 1_035_466_617_946;
    private const int MANUFACTURING_ACTIVITY_ID = 1;

    /** @var list<array{venue: string, structureId: ?int, fees: ?SalesFees}> */
    private array $scans = [];

    /** @var array<string, array<string, mixed>> */
    private array $slotStatusCharacters = [];

    /** @var array<string, array<int, int>> */
    private array $skillLevelsByCharacter = [];

    private ?SalesFees $salesFees = null;

    protected function setUp(): void
    {
        $this->salesFees = new SalesFees(0.01, 0.036);
    }

    public function testJitaAndThePreferredStructureAreScannedAndEveryRowKeepsItsMarket(): void
    {
        $user = $this->pilot(preferredStructureId: self::CAPITAL_HUB_ID, preferredStructureName: 'Capital Hub');

        $result = $this->opportunities()->for($user, OpportunityMarket::All, OpportunitySort::WeeklyProfit, null);

        self::assertSame([['venue' => 'jita', 'structureId' => null], ['venue' => 'structure', 'structureId' => self::CAPITAL_HUB_ID]], array_map(
            static fn (array $scan): array => ['venue' => $scan['venue'], 'structureId' => $scan['structureId']],
            $this->scans,
        ));
        self::assertSame('Capital Hub', $result->capitalStructureName);
        self::assertEqualsCanonicalizing(['jita', 'capital'], array_map(static fn ($row): string => $row->market, $result->rows));
    }

    public function testWithoutAPreferredStructureOnlyJitaIsScannedAndTheCapitalIsAbsent(): void
    {
        $result = $this->opportunities()->for($this->pilot(), OpportunityMarket::All, OpportunitySort::WeeklyProfit, null);

        self::assertSame(['jita'], array_column($this->scans, 'venue'));
        self::assertNull($result->capitalStructureName);
        self::assertSame(['jita'], array_map(static fn ($row): string => $row->market, $result->rows));
    }

    public function testTheCapitalMarketScansOnlyThePreferredStructure(): void
    {
        $user = $this->pilot(preferredStructureId: self::CAPITAL_HUB_ID, preferredStructureName: 'Capital Hub');

        $result = $this->opportunities()->for($user, OpportunityMarket::Capital, OpportunitySort::WeeklyProfit, null);

        self::assertSame(['structure'], array_column($this->scans, 'venue'));
        self::assertSame(['capital'], array_map(static fn ($row): string => $row->market, $result->rows));
    }

    public function testTheSellerSalesFeesOfTheUserArePassedToTheScannerAndReportedAsKnownOrNot(): void
    {
        $known = $this->opportunities()->for($this->pilot(), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, null);
        $this->salesFees = null;
        $unknown = $this->opportunities()->for($this->pilot(), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, null);

        self::assertEquals(new SalesFees(0.01, 0.036), $this->scans[0]['fees']);
        self::assertNull($this->scans[1]['fees']);
        self::assertTrue($known->salesFeesKnown);
        self::assertFalse($unknown->salesFeesKnown);
    }

    public function testFreeSlotsAreTheUnusedSlotsOfTheCharactersWithKnownSkillsNeverBelowZeroPerCharacter(): void
    {
        $this->slotStatusCharacters = [
            'Vessa' => $this->slotStatus(manufacturing: [5, 3], reaction: [0, 1], skillsUnknown: false),
            'Orrin' => $this->slotStatus(manufacturing: [1, 4], reaction: [1, 1], skillsUnknown: false),
            'Kaelen' => $this->slotStatus(manufacturing: [0, null], reaction: [0, null], skillsUnknown: true),
        ];
        $this->skillLevelsByCharacter = ['Vessa' => [], 'Orrin' => []];

        $result = $this->opportunities()->for($this->pilot(['Vessa', 'Orrin', 'Kaelen']), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, null);

        self::assertEquals(new FreeSlots(3, 1), $result->freeSlots);
    }

    public function testFreeSlotsAreUnknownWhenNoCharacterHasKnownSkills(): void
    {
        $this->slotStatusCharacters = ['Kaelen' => $this->slotStatus(manufacturing: [0, null], reaction: [0, null], skillsUnknown: true)];
        $this->skillLevelsByCharacter = [];

        $result = $this->opportunities()->for($this->pilot(['Kaelen']), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, null);

        self::assertNull($result->freeSlots);
        self::assertNull($result->rows[0]->weeklyQuantity);
    }

    public function testTheRequiredSkillsOfTheBlueprintAreComparedWithTheSkillsOfEachCharacterAndNamed(): void
    {
        $this->slotStatusCharacters = ['Vessa' => $this->slotStatus(manufacturing: [0, 5], reaction: [0, 1], skillsUnknown: false)];
        $this->skillLevelsByCharacter = ['Vessa' => [self::INDUSTRY_SKILL_ID => 5, self::HIGH_ENERGY_PHYSICS_SKILL_ID => 2]];

        $result = $this->opportunities()->for($this->pilot(['Vessa']), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, null);

        $row = $result->rows[0];
        self::assertCount(1, $row->missingSkills);
        self::assertSame(self::HIGH_ENERGY_PHYSICS_SKILL_ID, $row->missingSkills[0]->skillId);
        self::assertSame('High Energy Physics', $row->missingSkills[0]->name);
        self::assertSame(4, $row->missingSkills[0]->level);
    }

    public function testTheCapitalCapIsReportedAndLimitsTheQuantity(): void
    {
        $this->slotStatusCharacters = ['Vessa' => $this->slotStatus(manufacturing: [0, 5], reaction: [0, 1], skillsUnknown: false)];
        $this->skillLevelsByCharacter = ['Vessa' => [self::INDUSTRY_SKILL_ID => 5, self::HIGH_ENERGY_PHYSICS_SKILL_ID => 4]];

        $result = $this->opportunities()->for($this->pilot(['Vessa']), OpportunityMarket::Jita, OpportunitySort::WeeklyProfit, 1_400_000);

        self::assertSame(1_400_000, $result->capitalCap);
        self::assertSame(2, $result->rows[0]->weeklyQuantity);
    }

    /**
     * @param list<string> $characterNames
     */
    private function pilot(array $characterNames = [], ?int $preferredStructureId = null, ?string $preferredStructureName = null): User
    {
        $user = new User();
        foreach ($characterNames as $name) {
            $user->addCharacter((new Character())->setName($name));
        }
        $user->setPreferredMarketStructureId($preferredStructureId);
        $user->setPreferredMarketStructureName($preferredStructureName);

        return $user;
    }

    /**
     * @param array{int, ?int} $manufacturing used and maximum
     * @param array{int, ?int} $reaction used and maximum
     *
     * @return array<string, mixed>
     */
    private function slotStatus(array $manufacturing, array $reaction, bool $skillsUnknown): array
    {
        return [
            'skillsUnknown' => $skillsUnknown,
            'slots' => [
                'manufacturing' => ['used' => $manufacturing[0], 'max' => $manufacturing[1]],
                'reaction' => ['used' => $reaction[0], 'max' => $reaction[1]],
                'science' => ['used' => 0, 'max' => $skillsUnknown ? null : 1],
            ],
        ];
    }

    private function opportunities(): ProductionOpportunities
    {
        $scanner = $this->createStub(BatchProfitScannerService::class);
        $scanner->method('scan')->willReturnCallback(function (?string $category, ?float $minMargin, ?float $minVolume, string $venue, ?int $structureId, int $solarSystemId, ?SalesFees $fees): array {
            $this->scans[] = ['venue' => $venue, 'structureId' => $structureId, 'fees' => $fees];

            return [$this->scanRow()];
        });

        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(fn (): ?SalesFees => $this->salesFees);

        $slotTracker = $this->createStub(SlotTrackerService::class);
        $slotTracker->method('getSlotStatus')->willReturnCallback(fn (): array => [
            'characters' => array_values($this->slotStatusCharacters),
        ]);

        $skillRequirements = $this->createStub(IndustryActivitySkillRepository::class);
        $skillRequirements->method('findSkillsForBlueprints')->willReturnCallback(
            fn (array $blueprintTypeIds, int $activityId): array => self::MANUFACTURING_ACTIVITY_ID === $activityId
                ? [self::HAMMERHEAD_BLUEPRINT_ID => [
                    ['skillId' => self::INDUSTRY_SKILL_ID, 'level' => 1],
                    ['skillId' => self::HIGH_ENERGY_PHYSICS_SKILL_ID, 'level' => 4],
                ]]
                : [],
        );

        $activities = $this->createStub(IndustryActivityRepository::class);
        $activities->method('findByTypeIdsAndActivityIds')->willReturn([
            self::HAMMERHEAD_BLUEPRINT_ID.'-'.self::MANUFACTURING_ACTIVITY_ID => (new IndustryActivity())
                ->setTypeId(self::HAMMERHEAD_BLUEPRINT_ID)
                ->setActivityId(self::MANUFACTURING_ACTIVITY_ID)
                ->setTime(302_400),
        ]);

        $characterSkills = $this->createStub(CachedCharacterSkillRepository::class);
        $characterSkills->method('hasCachedSkills')->willReturnCallback(
            fn (Character $character): bool => \array_key_exists((string) $character->getName(), $this->skillLevelsByCharacter),
        );
        $characterSkills->method('findAllSkillsForCharacter')->willReturnCallback(function (Character $character): array {
            $skills = [];
            foreach ($this->skillLevelsByCharacter[(string) $character->getName()] ?? [] as $skillId => $level) {
                $skills[$skillId] = (new CachedCharacterSkill())->setSkillId($skillId)->setLevel($level);
            }

            return $skills;
        });

        $typeNames = $this->createStub(TypeNameResolver::class);
        $typeNames->method('findNames')->willReturn([
            self::HIGH_ENERGY_PHYSICS_SKILL_ID => 'High Energy Physics',
            self::INDUSTRY_SKILL_ID => 'Industry',
        ]);

        return new ProductionOpportunities(
            $scanner,
            new ProjectSellStructures(),
            $salesFeesResolver,
            $this->createStub(IndustryUserSettingsRepository::class),
            $slotTracker,
            $skillRequirements,
            $activities,
            $characterSkills,
            $typeNames,
            new ProductionOpportunityRanker(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scanRow(): array
    {
        return [
            'typeId' => self::HAMMERHEAD_TYPE_ID,
            'typeName' => 'Hammerhead II',
            'groupName' => 'Medium Drone',
            'categoryLabel' => 'T2',
            'marginPercent' => 9.71,
            'profitPerUnit' => 64_088.53,
            'dailyVolume' => 180.0,
            'iskPerDay' => 0.0,
            'materialCost' => 600_000.0,
            'importCost' => 0.0,
            'exportCost' => 0.0,
            'sellPrice' => 760_000.0,
            'unitCost' => 659_773.47,
            'blueprintTypeId' => self::HAMMERHEAD_BLUEPRINT_ID,
            'outputPerRun' => 1,
            'teUsed' => 0,
            'meUsed' => 2,
            'activityType' => 'manufacturing',
            'unknownReason' => null,
        ];
    }
}
