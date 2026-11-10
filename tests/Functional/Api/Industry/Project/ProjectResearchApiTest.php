<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryUserSettings;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-410')]
final class ProjectResearchApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_TIME_RESEARCH = 3;
    private const int ACTIVITY_MATERIAL_RESEARCH = 4;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_410_001;
    private const int SYNTHETIC_CATEGORY_ID = 999_410_002;
    private const int SYNTHETIC_GROUP_ID = 999_410_003;
    private const int ISHTAR_TRITANIUM_PER_RUN = 5_000;
    private const int ORIGINAL_QUANTITY = -1;
    private const int COPY_QUANTITY = -2;
    private const int ORIGINAL_RUNS = -1;
    private const int COPY_RUNS = 100;
    private const int BLUEPRINT_ITEM_ID = 9_410_000_001;
    private const int BLUEPRINT_LOCATION_ID = 60_003_760;
    private const float TRITANIUM_SELL_PRICE = 5.0;
    private const float TRITANIUM_ADJUSTED_PRICE = 4.0;
    private const float COST_INDEX = 0.05;
    private const int ISHTAR_ME_RESEARCH_BASE_TIME = 2_100;
    private const int ISHTAR_TE_RESEARCH_BASE_TIME = 2_800;
    private const int NO_RESEARCH_BASE_TIME = 0;

    private const array ROW_KEYS = [
        'currentLevel', 'materialSaving', 'meResearchDuration', 'missingData', 'net', 'researchCost', 'runs', 'targetLevel', 'teResearchDuration', 'typeId', 'typeName', 'verdict',
    ];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedIshtarBlueprintAndTheProjectProduct();
        $this->em->persist((new IndustryUserSettings())->setUser($this->owner)->setFavoriteManufacturingSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID));
        $this->em->flush();
        $this->seedJitaSellOrderBooks([self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_SELL_PRICE, 'volume' => 1_000_000]]]);
        $this->seedAdjustedPrices([self::TRITANIUM_TYPE_ID => self::TRITANIUM_ADJUSTED_PRICE]);
        $this->seedJitaCostIndex('manufacturing', self::COST_INDEX);
        $this->seedJitaCostIndex('researching_material_efficiency', self::COST_INDEX);
    }

    public function testAcceptedParticipantReadsTheResearchRowOfTheOwnedOriginalAtMe0WithItsSavingCostAndNet(): void
    {
        $this->ownTheIshtarBlueprint(self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $body['id']);
        self::assertCount(1, $body['rows']);
        $row = $body['rows'][0];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(self::ROW_KEYS, $keys);
        self::assertSame(
            [self::ISHTAR_TYPE_ID, 'Ishtar', 0, 1, self::ISHTAR_RUNS, 500.0, 29.0, 471.0, 'worth', []],
            [
                $row['typeId'], $row['typeName'], $row['currentLevel'], $row['targetLevel'], $row['runs'],
                $row['materialSaving'], $row['researchCost'], $row['net'], $row['verdict'], $row['missingData'],
            ],
        );
    }

    #[Group('issue-475')]
    public function testRowCarriesTheMeAndTeResearchDurationsOfTheCharacterWhoOwnsTheOriginal(): void
    {
        $this->ownTheIshtarBlueprint(self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS, materialEfficiency: 4, timeEfficiency: 6);
        $this->seedResearchBaseTimes(self::ISHTAR_ME_RESEARCH_BASE_TIME, self::ISHTAR_TE_RESEARCH_BASE_TIME);
        $this->cacheOwnerSkills([
            CachedCharacterSkill::SKILL_METALLURGY => 5,
            CachedCharacterSkill::SKILL_RESEARCH => 4,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4,
        ]);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $rows = $this->responseJson()['rows'];
        self::assertCount(1, $rows);
        self::assertSame(['targetLevel' => 5, 'seconds' => 44_352, 'missingData' => []], $rows[0]['meResearchDuration']);
        self::assertSame(['targetLevel' => 4, 'seconds' => 26_545, 'missingData' => []], $rows[0]['teResearchDuration']);
    }

    #[Group('issue-475')]
    public function testMissingSkillOrBaseTimeMakesADurationUnknownWithItsReasonAndLeavesTheVerdictUntouched(): void
    {
        $this->ownTheIshtarBlueprint(self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS);
        $this->seedResearchBaseTimes(self::ISHTAR_ME_RESEARCH_BASE_TIME, self::NO_RESEARCH_BASE_TIME);
        $this->cacheOwnerSkills([
            CachedCharacterSkill::SKILL_RESEARCH => 4,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4,
        ]);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $row = $this->responseJson()['rows'][0];
        self::assertSame(
            ['targetLevel' => 1, 'seconds' => null, 'missingData' => [['reason' => 'research_skill', 'typeId' => CachedCharacterSkill::SKILL_METALLURGY]]],
            $row['meResearchDuration'],
        );
        self::assertSame(
            ['targetLevel' => 1, 'seconds' => null, 'missingData' => [['reason' => 'research_base_time', 'typeId' => self::ISHTAR_TYPE_ID]]],
            $row['teResearchDuration'],
        );
        self::assertSame(
            [self::ISHTAR_TYPE_ID, 500.0, 29.0, 471.0, 'worth', []],
            [$row['typeId'], $row['materialSaving'], $row['researchCost'], $row['net'], $row['verdict'], $row['missingData']],
        );
    }

    #[Group('issue-475')]
    public function testOriginalAtTe20HasAnExplicitNullTeResearchDuration(): void
    {
        $this->ownTheIshtarBlueprint(self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS, timeEfficiency: 20);
        $this->seedResearchBaseTimes(self::ISHTAR_ME_RESEARCH_BASE_TIME, self::ISHTAR_TE_RESEARCH_BASE_TIME);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $row = $this->responseJson()['rows'][0];
        self::assertArrayHasKey('teResearchDuration', $row);
        self::assertNull($row['teResearchDuration']);
        self::assertSame(1, $row['meResearchDuration']['targetLevel']);
    }

    public function testPilotOfAnotherCorporationIsRefusedTheResearch(): void
    {
        $this->ownTheIshtarBlueprint(self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS);

        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testProjectWhoseOwnerOwnsOnlyACopyOfTheProductBlueprintHasNoResearchRow(): void
    {
        $this->ownTheIshtarBlueprint(self::COPY_QUANTITY, self::COPY_RUNS);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->researchUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $body['id']);
        self::assertSame([], $body['rows']);
    }

    private function researchUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/research';
    }

    private function seedResearchBaseTimes(int $materialResearchSeconds, int $timeResearchSeconds): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('sde_industry_activities', ['type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_MATERIAL_RESEARCH, 'time' => $materialResearchSeconds]);
        $connection->insert('sde_industry_activities', ['type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_TIME_RESEARCH, 'time' => $timeResearchSeconds]);
    }

    private function cacheOwnerSkills(array $levelsBySkillId): void
    {
        foreach ($levelsBySkillId as $skillId => $level) {
            $this->em->persist((new CachedCharacterSkill())->setCharacter($this->owner->getMainCharacter())->setSkillId($skillId)->setLevel($level));
        }
        $this->em->flush();
    }

    private function ownTheIshtarBlueprint(int $quantity, int $runs, int $materialEfficiency = 0, int $timeEfficiency = 0): void
    {
        $this->em->persist(
            (new OwnedBlueprint())
                ->setItemId(self::BLUEPRINT_ITEM_ID)
                ->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
                ->setLocationId(self::BLUEPRINT_LOCATION_ID)
                ->setLocationFlag('Hangar')
                ->setQuantity($quantity)
                ->setRuns($runs)
                ->setMaterialEfficiency($materialEfficiency)
                ->setTimeEfficiency($timeEfficiency)
                ->setCharacter($this->owner->getMainCharacter())
                ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00')),
        );
        $this->em->flush();
    }

    private function seedIshtarBlueprintAndTheProjectProduct(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SYNTHETIC_CATEGORY_ID)->setCategoryName('Ship');
        $group = (new InvGroup())->setGroupId(self::SYNTHETIC_GROUP_ID)->setGroupName('Heavy Assault Cruiser')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach ([self::ISHTAR_TYPE_ID => 'Ishtar', self::TRITANIUM_TYPE_ID => 'Tritanium'] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group));
        }
        $this->ishtarBatch->addItem((new IndustryProjectProduct())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setMeLevel(4)
            ->setTeLevel(8)
            ->setRuns(self::ISHTAR_RUNS)
            ->setSortOrder(0));
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('sde_industry_blueprints', ['type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID, 'max_production_limit' => 200]);
        $connection->insert('sde_industry_activities', ['type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
        $connection->insert('sde_industry_activity_products', [
            'type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::ISHTAR_TYPE_ID,
            'quantity' => 1,
        ]);
        $connection->insert('sde_industry_activity_materials', [
            'type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'material_type_id' => self::TRITANIUM_TYPE_ID,
            'quantity' => self::ISHTAR_TRITANIUM_PER_RUN,
        ]);
    }
}
