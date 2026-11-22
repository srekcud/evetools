<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectProduct;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\IndustryProjectService;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1205')]
final class ProjectInventionDecryptorApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_INVENTION = 8;
    private const int VEXOR_BLUEPRINT_TYPE_ID = 999_205_001;
    private const int VEXOR_TYPE_ID = 999_205_002;
    private const int DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID = 999_205_003;
    private const int DATACORE_MECHANICAL_ENGINEERING_TYPE_ID = 999_205_004;
    private const int ATTAINMENT_DECRYPTOR_TYPE_ID = 999_205_005;
    private const int DECRYPTOR_GROUP_ID = 1304;
    private const int ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER = 1112;
    private const int ATTRIBUTE_INVENTION_ME_MODIFIER = 1113;
    private const int ATTRIBUTE_INVENTION_TE_MODIFIER = 1114;
    private const int ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER = 1124;
    private const int INVENTION_BASE_RUNS = 2;
    private const float INVENTION_PROBABILITY = 0.4;
    private const int ISHTAR_RUNS_IN_THE_PLAN = 15;
    private const int ATTEMPTS_WITHOUT_DECRYPTOR = 19;
    private const int ATTEMPTS_WITH_ATTAINMENT = 4;
    private const int ELECTRONIC_ENGINEERING_PER_ATTEMPT = 3;
    private const int MECHANICAL_ENGINEERING_PER_ATTEMPT = 5;
    private const int MAXIMUM_INVENTION_DECRYPTORS = 100;
    private const int ABOVE_INT4 = 2_147_483_648;

    private IndustryProject $soloIshtar;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->seedTheInventionOfTheIshtarAndAnAttainmentDecryptor();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(
            self::RAM_STARSHIP_TECH_TYPE_ID,
            self::TRITANIUM_TYPE_ID,
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID,
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID,
            self::ATTAINMENT_DECRYPTOR_TYPE_ID,
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
        $this->em->flush();
    }

    public function testOwnerChoosesTheDecryptorOfAnInventedProductAndThePlanNeedsFourAttemptsAndFourDecryptors(): void
    {
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_WITHOUT_DECRYPTOR]);

        $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => self::ATTAINMENT_DECRYPTOR_TYPE_ID]);

        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        self::assertSame([self::ISHTAR_TYPE_ID => self::ATTAINMENT_DECRYPTOR_TYPE_ID], $project->inventionDecryptorsByProductTypeId());
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_WITH_ATTAINMENT]);
        self::assertEqualsCanonicalizing(
            [
                self::ATTAINMENT_DECRYPTOR_TYPE_ID => self::ATTEMPTS_WITH_ATTAINMENT,
                self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID => self::ELECTRONIC_ENGINEERING_PER_ATTEMPT * self::ATTEMPTS_WITH_ATTAINMENT,
                self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID => self::MECHANICAL_ENGINEERING_PER_ATTEMPT * self::ATTEMPTS_WITH_ATTAINMENT,
            ],
            $this->inventionMaterialQuantitiesInThePlan(),
        );
    }

    public function testComparisonReportsTheDecryptorChosenOnTheProductThenNullOnceRemoved(): void
    {
        $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => self::ATTAINMENT_DECRYPTOR_TYPE_ID]);
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->soloIshtar).'/invention');
        $chosen = array_map(static fn (array $product): ?int => $product['selectedDecryptorTypeId'], $this->responseJson()['products']);

        $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => null]);
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->soloIshtar).'/invention');
        $removed = array_map(static fn (array $product): ?int => $product['selectedDecryptorTypeId'], $this->responseJson()['products']);

        self::assertSame([[self::ATTAINMENT_DECRYPTOR_TYPE_ID], [null]], [$chosen, $removed]);
    }

    public function testMemberWhoIsNeitherOwnerNorAdminCannotChooseTheDecryptor(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::Draft);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['inventionDecryptors' => [self::ISHTAR_TYPE_ID => self::ATTAINMENT_DECRYPTOR_TYPE_ID]]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->inventionDecryptorsByProductTypeId());
    }

    public function testTypeThatIsNotADecryptorOfTheSdeIsRefusedAndThePlanIsUnchanged(): void
    {
        $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => self::TRITANIUM_TYPE_ID]);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->soloIshtar->getId())->inventionDecryptorsByProductTypeId());
        $this->assertInventionRunsInThePlan([self::ATTEMPTS_WITHOUT_DECRYPTOR]);
    }

    public function testChoiceOnACompletedProjectIsRefused(): void
    {
        $this->soloIshtar->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => self::ATTAINMENT_DECRYPTOR_TYPE_ID]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'The invention decryptor of a completed project cannot be changed'], $this->errorDetail());
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->soloIshtar->getId())->inventionDecryptorsByProductTypeId());
    }

    public function testMoreThanOneHundredChoicesAreRefused(): void
    {
        $choices = [];
        foreach (range(1, self::MAXIMUM_INVENTION_DECRYPTORS + 1) as $offset) {
            $choices[100_000 + $offset] = null;
        }

        $this->patchInventionDecryptors($choices);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDecryptorValuesThatAreNotPositiveIntegersAreRefusedAndNothingIsStored(): void
    {
        foreach (['not-a-type-id', 0, -34_202, 34_202.5, self::ABOVE_INT4] as $invalidValue) {
            $this->patchInventionDecryptors([self::ISHTAR_TYPE_ID => $invalidValue]);

            self::assertResponseStatusCodeSame(422, var_export($invalidValue, true));
        }
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->soloIshtar->getId())->inventionDecryptorsByProductTypeId());
    }

    public function testKeysThatAreNotCanonicalProductTypeIdsAreRefused(): void
    {
        foreach (['01', 'abc', '-5', '1e3'] as $invalidKey) {
            $this->patchInventionDecryptors([$invalidKey => self::ATTAINMENT_DECRYPTOR_TYPE_ID]);

            self::assertResponseStatusCodeSame(422, $invalidKey);
        }
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->soloIshtar->getId())->inventionDecryptorsByProductTypeId());
    }

    private function patchInventionDecryptors(array $choices): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->soloIshtar), ['inventionDecryptors' => $choices]);
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

    private function inventionMaterialQuantitiesInThePlan(): array
    {
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        $quantities = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob() && \in_array($line->getTypeId(), [self::ATTAINMENT_DECRYPTOR_TYPE_ID, self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID, self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID], true)) {
                $quantities[$line->getTypeId()] = $line->getRequiredQuantity();
            }
        }

        return $quantities;
    }

    private function seedTheInventionOfTheIshtarAndAnAttainmentDecryptor(): void
    {
        $group = $this->em->find(InvGroup::class, self::SYNTHETIC_GROUP_ID);
        $decryptorGroup = $this->em->find(InvGroup::class, self::DECRYPTOR_GROUP_ID)
            ?? (new InvGroup())->setGroupId(self::DECRYPTOR_GROUP_ID)->setGroupName('Generic Decryptor')->setCategory($group->getCategory());
        $this->em->persist($decryptorGroup);
        foreach ([
            self::VEXOR_TYPE_ID => ['Vexor', $group],
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID => ['Datacore - Electronic Engineering', $group],
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID => ['Datacore - Mechanical Engineering', $group],
            self::ATTAINMENT_DECRYPTOR_TYPE_ID => ['Attainment Decryptor', $decryptorGroup],
        ] as $typeId => [$typeName, $typeGroup]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($typeGroup));
        }
        $this->em->persist((new DgmTypeAttribute())->setTypeId(self::ATTAINMENT_DECRYPTOR_TYPE_ID)->setAttributeId(self::ATTRIBUTE_INVENTION_PROBABILITY_MULTIPLIER)->setValueFloat(1.8));
        foreach ([
            self::ATTRIBUTE_INVENTION_ME_MODIFIER => -1,
            self::ATTRIBUTE_INVENTION_TE_MODIFIER => 4,
            self::ATTRIBUTE_INVENTION_MAX_RUN_MODIFIER => 4,
        ] as $attributeId => $value) {
            $this->em->persist((new DgmTypeAttribute())->setTypeId(self::ATTAINMENT_DECRYPTOR_TYPE_ID)->setAttributeId($attributeId)->setValueInt($value));
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
        foreach ([
            self::DATACORE_ELECTRONIC_ENGINEERING_TYPE_ID => self::ELECTRONIC_ENGINEERING_PER_ATTEMPT,
            self::DATACORE_MECHANICAL_ENGINEERING_TYPE_ID => self::MECHANICAL_ENGINEERING_PER_ATTEMPT,
        ] as $datacoreTypeId => $quantity) {
            $connection->insert('sde_industry_activity_materials', [
                'type_id' => self::VEXOR_BLUEPRINT_TYPE_ID,
                'activity_id' => self::ACTIVITY_INVENTION,
                'material_type_id' => $datacoreTypeId,
                'quantity' => $quantity,
            ]);
        }
    }
}
