<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Application\ResearchBaseTimes;
use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Sde\SdeResearchBaseTimes;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-475')]
final class SdeResearchBaseTimesTest extends IntegrationTestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_RESEARCH_TIME = 3;
    private const int ACTIVITY_RESEARCH_MATERIAL = 4;
    private const int ACTIVITY_COPYING = 5;
    private const int ACTIVITY_REACTION = 11;

    private const int RESEARCHED_PRODUCT = 990_475_001;
    private const int RESEARCHED_BLUEPRINT = 990_475_002;
    private const int PRODUCT_WITHOUT_MATERIAL_TIME = 990_475_003;
    private const int BLUEPRINT_WITHOUT_MATERIAL_TIME = 990_475_004;
    private const int PRODUCT_WITHOUT_TIME_ROW = 990_475_005;
    private const int BLUEPRINT_WITHOUT_TIME_ROW = 990_475_006;
    private const int REACTION_ONLY_PRODUCT = 990_475_007;
    private const int REACTION_FORMULA = 990_475_008;
    private const int UNKNOWN_PRODUCT = 990_475_009;

    private const int RESEARCHED_ME_SECONDS = 2_100;
    private const int RESEARCHED_TE_SECONDS = 2_800;
    private const int TE_SECONDS_WITHOUT_MATERIAL_TIME = 3_150;
    private const int ME_SECONDS_WITHOUT_TIME_ROW = 4_200;
    private const int HORNET_COPY_SECONDS = 240;
    private const int COPIED_PRODUCT = 990_524_011;
    private const int COPIED_BLUEPRINT = 990_524_012;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testSdeResearchBaseTimesIsTheResearchBaseTimesOfTheApplication(): void
    {
        $this->assertInstanceOf(SdeResearchBaseTimes::class, self::getContainer()->get(ResearchBaseTimes::class));
    }

    public function testManufacturingIsNotAResearchActivity(): void
    {
        $this->insertResearchedBlueprint();

        $this->expectException(\InvalidArgumentException::class);

        $this->baseTimes()->of(self::RESEARCHED_PRODUCT, ActivityKind::Manufacturing);
    }

    public function testMeAndTeBaseTimesAreReadFromTheirOwnResearchActivityOfTheManufacturingBlueprint(): void
    {
        $this->insertResearchedBlueprint();

        self::assertSame(self::RESEARCHED_ME_SECONDS, $this->baseTimes()->of(self::RESEARCHED_PRODUCT, ActivityKind::ResearchMaterial));
        self::assertSame(self::RESEARCHED_TE_SECONDS, $this->baseTimes()->of(self::RESEARCHED_PRODUCT, ActivityKind::ResearchTime));
    }

    public function testProductWithoutManufacturingBlueprintHasNoBaseTime(): void
    {
        $this->insertReactionOnlyProduct();

        self::assertNull($this->baseTimes()->of(self::UNKNOWN_PRODUCT, ActivityKind::ResearchMaterial));
        self::assertNull($this->baseTimes()->of(self::UNKNOWN_PRODUCT, ActivityKind::ResearchTime));
        self::assertNull($this->baseTimes()->of(self::REACTION_ONLY_PRODUCT, ActivityKind::ResearchMaterial));
        self::assertNull($this->baseTimes()->of(self::REACTION_ONLY_PRODUCT, ActivityKind::ResearchTime));
    }

    public function testZeroBaseTimeIsUnknownWhileTheOtherActivityKeepsItsOwnTime(): void
    {
        $this->insertBlueprint(self::BLUEPRINT_WITHOUT_MATERIAL_TIME, self::PRODUCT_WITHOUT_MATERIAL_TIME);
        $this->insertActivity(self::BLUEPRINT_WITHOUT_MATERIAL_TIME, self::ACTIVITY_RESEARCH_MATERIAL, 0);
        $this->insertActivity(self::BLUEPRINT_WITHOUT_MATERIAL_TIME, self::ACTIVITY_RESEARCH_TIME, self::TE_SECONDS_WITHOUT_MATERIAL_TIME);

        self::assertNull($this->baseTimes()->of(self::PRODUCT_WITHOUT_MATERIAL_TIME, ActivityKind::ResearchMaterial));
        self::assertSame(self::TE_SECONDS_WITHOUT_MATERIAL_TIME, $this->baseTimes()->of(self::PRODUCT_WITHOUT_MATERIAL_TIME, ActivityKind::ResearchTime));
    }

    public function testMissingResearchActivityRowIsUnknownWhileTheOtherActivityKeepsItsOwnTime(): void
    {
        $this->insertBlueprint(self::BLUEPRINT_WITHOUT_TIME_ROW, self::PRODUCT_WITHOUT_TIME_ROW);
        $this->insertActivity(self::BLUEPRINT_WITHOUT_TIME_ROW, self::ACTIVITY_RESEARCH_MATERIAL, self::ME_SECONDS_WITHOUT_TIME_ROW);

        self::assertSame(self::ME_SECONDS_WITHOUT_TIME_ROW, $this->baseTimes()->of(self::PRODUCT_WITHOUT_TIME_ROW, ActivityKind::ResearchMaterial));
        self::assertNull($this->baseTimes()->of(self::PRODUCT_WITHOUT_TIME_ROW, ActivityKind::ResearchTime));
    }

    #[Group('issue-524')]
    public function testCopyBaseTimeIsReadFromTheCopyActivityOfTheManufacturingBlueprintAndIsUnknownWithoutIt(): void
    {
        $this->insertBlueprint(self::COPIED_BLUEPRINT, self::COPIED_PRODUCT);
        $this->insertActivity(self::COPIED_BLUEPRINT, self::ACTIVITY_COPYING, self::HORNET_COPY_SECONDS);
        $this->insertBlueprint(self::BLUEPRINT_WITHOUT_TIME_ROW, self::PRODUCT_WITHOUT_TIME_ROW);

        self::assertSame(
            [self::HORNET_COPY_SECONDS, null],
            [$this->baseTimes()->of(self::COPIED_PRODUCT, ActivityKind::Copying), $this->baseTimes()->of(self::PRODUCT_WITHOUT_TIME_ROW, ActivityKind::Copying)],
        );
    }

    private function baseTimes(): SdeResearchBaseTimes
    {
        return self::getContainer()->get(SdeResearchBaseTimes::class);
    }

    private function insertResearchedBlueprint(): void
    {
        $this->insertBlueprint(self::RESEARCHED_BLUEPRINT, self::RESEARCHED_PRODUCT);
        $this->insertActivity(self::RESEARCHED_BLUEPRINT, self::ACTIVITY_RESEARCH_MATERIAL, self::RESEARCHED_ME_SECONDS);
        $this->insertActivity(self::RESEARCHED_BLUEPRINT, self::ACTIVITY_RESEARCH_TIME, self::RESEARCHED_TE_SECONDS);
    }

    private function insertReactionOnlyProduct(): void
    {
        $this->connection->insert('sde_industry_blueprints', ['type_id' => self::REACTION_FORMULA, 'max_production_limit' => 200]);
        $this->insertActivity(self::REACTION_FORMULA, self::ACTIVITY_RESEARCH_MATERIAL, self::RESEARCHED_ME_SECONDS);
        $this->insertActivity(self::REACTION_FORMULA, self::ACTIVITY_RESEARCH_TIME, self::RESEARCHED_TE_SECONDS);
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => self::REACTION_FORMULA,
            'activity_id' => self::ACTIVITY_REACTION,
            'product_type_id' => self::REACTION_ONLY_PRODUCT,
            'quantity' => 1,
        ]);
    }

    private function insertBlueprint(int $blueprintTypeId, int $productTypeId): void
    {
        $this->connection->insert('sde_industry_blueprints', ['type_id' => $blueprintTypeId, 'max_production_limit' => 200]);
        $this->insertActivity($blueprintTypeId, self::ACTIVITY_MANUFACTURING, 900);
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => $blueprintTypeId,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => $productTypeId,
            'quantity' => 1,
        ]);
    }

    private function insertActivity(int $blueprintTypeId, int $activityId, int $baseTimeSeconds): void
    {
        $this->connection->insert('sde_industry_activities', [
            'type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'time' => $baseTimeSeconds,
        ]);
    }
}
