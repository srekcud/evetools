<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectProduct;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\IndustryProjectService;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-804')]
final class ProjectT2BlueprintCopyApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_INVENTION = 8;
    private const int VEXOR_BLUEPRINT_TYPE_ID = 999_804_001;
    private const int VEXOR_TYPE_ID = 999_804_002;
    private const int DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID = 999_804_003;
    private const int DATACORE_MECHANICAL_ENGINEERING_TYPE_ID = 999_804_004;
    private const int INVENTION_BASE_RUNS = 2;
    private const float INVENTION_PROBABILITY = 0.4;
    private const int PERSONAL_COPY_RUNS = 10;
    private const int OTHER_PERSONAL_COPY_RUNS = 6;
    private const int ISHTAR_RUNS_IN_THE_PLAN = 15;
    private const int ATTEMPTS_FOR_ALL_RUNS = 19;
    private const int ATTEMPTS_FOR_FIVE_RUNS = 7;
    private const int MAXIMUM_INT4 = 2_147_483_647;
    private const int MAXIMUM_LIST_SIZE_ANY_LIMIT_MUST_REFUSE = 5_001;

    private IndustryProject $soloIshtar;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->seedTheInventionOfTheIshtar();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(
            self::RAM_STARSHIP_TECH_TYPE_ID,
            self::TRITANIUM_TYPE_ID,
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID,
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID,
        );
        $this->soloIshtar = $this->persistProject('Solo Ishtar');
        $this->soloIshtar->setStatus(ProjectStatus::Draft)->enrolOwner();
        $this->soloIshtar->addItem((new IndustryProjectProduct())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setMeLevel(4)
            ->setTeLevel(8)
            ->setRuns(self::ISHTAR_RUNS_IN_THE_PLAN)
            ->setSortOrder(0));
        $this->em->flush();
        self::getContainer()->get(IndustryProjectService::class)->buildBom($this->soloIshtar, $this->owner);
        $this->persistPersonalCopy(1_080_400_100_001, self::PERSONAL_COPY_RUNS);
        $this->persistPersonalCopy(1_080_400_100_002, self::OTHER_PERSONAL_COPY_RUNS);
        $this->em->flush();
    }

    public function testOwnerReadsTheCopiesOfTheirBlueprintSyncForTheInventedProductOfTheirSoloProject(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->proposalUri($this->soloIshtar->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(['ready', $this->soloIshtar->getId()->toRfc4122()], [$resource['state'], $resource['id']]);
        self::assertCount(1, $resource['lines']);
        $line = $resource['lines'][0];
        self::assertArrayHasKey('containerRuns', $line);
        self::assertNull($line['containerRuns']);
        unset($line['containerRuns']);
        ksort($line);
        self::assertSame(
            [
                'availableRuns' => 16,
                'declaredRuns' => 0,
                'manufacturingRuns' => self::ISHTAR_RUNS_IN_THE_PLAN,
                'personalRuns' => 16,
                'productTypeId' => self::ISHTAR_TYPE_ID,
                'proposedRuns' => self::ISHTAR_RUNS_IN_THE_PLAN,
                'typeName' => 'Ishtar',
            ],
            $line,
        );
    }

    public function testPilotWhoDoesNotOwnTheProjectIsRefusedOnTheProposal(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->proposalUri($this->soloIshtar->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testProposalOfAnUnknownProjectIsNotFound(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->proposalUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testOwnerDeclaringEveryRunOfTheProductGetsAPlanWithoutInventionCopyOrDatacoreAndTheDeclarationIsReturned(): void
    {
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);

        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => self::ISHTAR_RUNS_IN_THE_PLAN]]]);

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertSame(['ready', [[self::ISHTAR_TYPE_ID, self::ISHTAR_RUNS_IN_THE_PLAN]]], [$resource['state'], array_map(static fn (array $line): array => [$line['productTypeId'], $line['declaredRuns']], $resource['lines'])]);
        $this->assertInventionRunsInThePlan([]);
        $this->assertNoDatacoreInThePlan();
    }

    public function testOwnerDeclaringPartOfTheRunsGetsTheInventionOfTheRemainingRunsOnly(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => 10]]]);

        self::assertResponseStatusCodeSame(200);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_FIVE_RUNS]);
    }

    public function testOwnerDeclaringTheSameProductAgainWithOtherRunsReplacesTheDeclarationThenWithdrawingRestoresTheFullInvention(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => 10]]]);
        self::assertResponseStatusCodeSame(200);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_FIVE_RUNS]);

        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => self::ISHTAR_RUNS_IN_THE_PLAN]]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::ISHTAR_RUNS_IN_THE_PLAN], array_map(static fn (array $line): int => $line['declaredRuns'], $this->responseJson()['lines']));
        $this->assertInventionRunsInThePlan([]);

        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => []]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([0], array_map(static fn (array $line): int => $line['declaredRuns'], $this->responseJson()['lines']));
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);
    }

    public function testRunsAboveTheProposedRunsAreRefusedAndThePlanIsUnchanged(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => self::ISHTAR_RUNS_IN_THE_PLAN + 1]]]);

        self::assertResponseStatusCodeSame(400);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);
    }

    public function testProductThatIsNotInTheProposalIsRefused(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::TRITANIUM_TYPE_ID, 'runs' => 1]]]);

        self::assertResponseStatusCodeSame(400);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);
    }

    public function testPilotWhoDoesNotOwnTheProjectCannotDeclareAndThePlanIsUnchanged(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => 10]]]);

        self::assertResponseStatusCodeSame(403);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);
    }

    public function testBodyWithoutTheCopiesListIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), []);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies', $this->violatedPaths());
    }

    public function testEntryWithoutProductTypeIdIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['runs' => 10]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies[0].productTypeId', $this->violatedPaths());
    }

    public function testEntryWithoutRunsIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies[0].runs', $this->violatedPaths());
    }

    public function testProductTypeIdAboveTheIntegerRangeIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::MAXIMUM_INT4 + 1, 'runs' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies[0].productTypeId', $this->violatedPaths());
    }

    public function testRunsAboveTheIntegerRangeAreRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => self::MAXIMUM_INT4 + 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies[0].runs', $this->violatedPaths());
    }

    public function testListLongerThanAnyPossibleProposalIsRejected(): void
    {
        $this->actAs($this->owner);
        $copies = array_map(static fn (int $offset): array => ['productTypeId' => self::ISHTAR_TYPE_ID + $offset, 'runs' => 1], range(1, self::MAXIMUM_LIST_SIZE_ANY_LIMIT_MUST_REFUSE));
        $this->request('POST', $this->declarationUri(), ['copies' => $copies]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('copies', $this->violatedPaths());
    }

    public function testSameProductDeclaredTwiceIsRefusedAndThePlanIsUnchanged(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->declarationUri(), ['copies' => [
            ['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => 4],
            ['productTypeId' => self::ISHTAR_TYPE_ID, 'runs' => 5],
        ]]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_FOR_ALL_RUNS]);
    }

    private function proposalUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/t2-blueprint-copy-proposal';
    }

    private function declarationUri(): string
    {
        return '/api/industry/projects/'.$this->soloIshtar->getId()->toRfc4122().'/t2-blueprint-copies';
    }

    private function persistPersonalCopy(int $itemId, int $runs): void
    {
        $this->em->persist((new OwnedBlueprint())
            ->setItemId($itemId)
            ->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setLocationId(60_003_760)
            ->setLocationFlag('Hangar')
            ->setQuantity(-2)
            ->setMaterialEfficiency(2)
            ->setTimeEfficiency(4)
            ->setRuns($runs)
            ->setCharacter($this->owner->getMainCharacter())
            ->setSyncedAt(new \DateTimeImmutable('2026-11-13T08:00:00+00:00')));
    }

    private function seedTheInventionOfTheIshtar(): void
    {
        $group = $this->em->find(InvGroup::class, self::SYNTHETIC_GROUP_ID);
        foreach ([
            self::VEXOR_TYPE_ID => 'Vexor',
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID => 'Datacore - Electronic Engineering',
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID => 'Datacore - Mechanical Engineering',
        ] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group));
        }
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('sde_industry_blueprints', ['type_id' => self::VEXOR_BLUEPRINT_TYPE_ID, 'max_production_limit' => 200]);
        $connection->insert('sde_industry_activities', ['type_id' => self::VEXOR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
        $connection->insert('sde_industry_activities', ['type_id' => self::VEXOR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_INVENTION, 'time' => 900]);
        $connection->insert('sde_industry_activity_products', [
            'type_id' => self::VEXOR_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::VEXOR_TYPE_ID,
            'quantity' => 1,
        ]);
        $connection->insert('sde_industry_activity_products', [
            'type_id' => self::VEXOR_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_INVENTION,
            'product_type_id' => self::ISHTAR_BLUEPRINT_TYPE_ID,
            'quantity' => self::INVENTION_BASE_RUNS,
            'probability' => self::INVENTION_PROBABILITY,
        ]);
        foreach ([self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID => 3, self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID => 5] as $datacoreTypeId => $quantity) {
            $connection->insert('sde_industry_activity_materials', [
                'type_id' => self::VEXOR_BLUEPRINT_TYPE_ID,
                'activity_id' => self::ACTIVITY_INVENTION,
                'material_type_id' => $datacoreTypeId,
                'quantity' => $quantity,
            ]);
        }
    }

    private function assertInventionRunsInThePlan(array $expectedRuns): void
    {
        $this->em->clear();
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        $runs = array_values(array_map(
            static fn (IndustryProjectBomLine $line): ?int => $line->getRuns(),
            array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => 'invention' === $line->getActivityType()),
        ));
        self::assertSame($expectedRuns, $runs);
    }

    private function assertNoDatacoreInThePlan(): void
    {
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        $typeIds = array_map(static fn (IndustryProjectBomLine $line): int => $line->getTypeId(), $project->getBomItems()->toArray());
        self::assertSame([], array_values(array_intersect($typeIds, [
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID,
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID,
            self::VEXOR_TYPE_ID,
        ])));
    }
}
