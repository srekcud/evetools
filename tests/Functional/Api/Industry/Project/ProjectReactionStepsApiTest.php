<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-345')]
final class ProjectReactionStepsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int FULLERIDES_TYPE_ID = 345_001;
    private const int FULLERIDES_BLUEPRINT_TYPE_ID = 345_501;
    private const int CARBON_POLYMERS_TYPE_ID = 345_101;
    private const int PLATINUM_TECHNITE_TYPE_ID = 345_102;
    private const int NITROGEN_FUEL_BLOCK_TYPE_ID = 345_103;
    private const int REACTION_CATEGORY_ID = 345;
    private const int REACTION_PRODUCT_GROUP_ID = 345_201;
    private const int REACTION_MATERIAL_GROUP_ID = 345_202;
    private const int FUEL_BLOCK_GROUP_ID = 1_136;
    private const int FULLERIDES_OUTPUT_PER_RUN = 3_000;
    private const int FULLERIDES_SECONDS_PER_RUN = 10_800;
    private const int FULLERIDES_REQUIRED_QUANTITY = 3_000;
    private const int FULLERIDES_SURPLUS_QUANTITY = 2_509;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testOwnerReadsTheReactionStepOfHisProjectWithItsFormulaItsBaseDurationAndItsSurplus(): void
    {
        $this->seedFulleridesFormula();
        $this->addFulleridesReactionLine($this->ishtarBatch);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->reactionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            $this->canonical([
                'id' => $this->ishtarBatch->getId()->toRfc4122(),
                'steps' => [
                    [
                        'typeId' => self::FULLERIDES_TYPE_ID,
                        'typeName' => 'Fullerides',
                        'runs' => 1,
                        'producedQuantity' => 3_000,
                        'surplusQuantity' => 2_509,
                        'neededQuantity' => 491,
                        'outputPerRun' => 3_000,
                        'baseSecondsPerRun' => 10_800,
                        'baseTotalSeconds' => 10_800,
                        'inputs' => [
                            ['typeId' => self::CARBON_POLYMERS_TYPE_ID, 'typeName' => 'Carbon Polymers', 'quantityPerRun' => 100, 'totalQuantity' => 100, 'isFuelBlock' => false],
                            ['typeId' => self::PLATINUM_TECHNITE_TYPE_ID, 'typeName' => 'Platinum Technite', 'quantityPerRun' => 100, 'totalQuantity' => 100, 'isFuelBlock' => false],
                            ['typeId' => self::NITROGEN_FUEL_BLOCK_TYPE_ID, 'typeName' => 'Nitrogen Fuel Block', 'quantityPerRun' => 5, 'totalQuantity' => 5, 'isFuelBlock' => true],
                        ],
                    ],
                ],
            ]),
            $this->canonical($this->responseJson()),
        );
    }

    public function testStepWhoseFormulaIsMissingFromTheSdeIsListedWithItsBomQuantitiesAndNullKeysForTheFormula(): void
    {
        $this->addFulleridesReactionLine($this->ishtarBatch);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->reactionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            $this->canonical([
                'id' => $this->ishtarBatch->getId()->toRfc4122(),
                'steps' => [
                    [
                        'typeId' => self::FULLERIDES_TYPE_ID,
                        'typeName' => 'Fullerides',
                        'runs' => 1,
                        'producedQuantity' => 3_000,
                        'surplusQuantity' => 2_509,
                        'neededQuantity' => 491,
                        'outputPerRun' => null,
                        'baseSecondsPerRun' => null,
                        'baseTotalSeconds' => null,
                        'inputs' => null,
                    ],
                ],
            ]),
            $this->canonical($this->responseJson()),
        );
    }

    public function testProjectWithoutReactionHasNoStep(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->reactionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['id' => $this->ishtarBatch->getId()->toRfc4122(), 'steps' => []],
            $this->canonical($this->responseJson()),
        );
    }

    public function testPilotWhoDoesNotTakePartInTheProjectIsRefusedItsReactionSteps(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->reactionsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testReactionStepsOfAnUnknownProjectAreNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->reactionsUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function reactionsUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/reactions';
    }

    private function addFulleridesReactionLine(IndustryProject $project): void
    {
        $this->addJobLine($project, self::FULLERIDES_TYPE_ID, 'Fullerides', 'component', 1, self::FULLERIDES_REQUIRED_QUANTITY, 0, 0, self::ISHTAR_TYPE_ID)
            ->setActivityType('reaction')
            ->setSurplusQuantity(self::FULLERIDES_SURPLUS_QUANTITY);
    }

    private function seedFulleridesFormula(): void
    {
        $category = (new InvCategory())->setCategoryId(self::REACTION_CATEGORY_ID)->setCategoryName('Test Reaction');
        $this->em->persist($category);
        $productGroup = $this->group(self::REACTION_PRODUCT_GROUP_ID, 'Test Reaction Product', $category);
        $materialGroup = $this->group(self::REACTION_MATERIAL_GROUP_ID, 'Test Reaction Material', $category);
        $fuelBlockGroup = $this->group(self::FUEL_BLOCK_GROUP_ID, 'Fuel Block', $category);
        $this->type(self::FULLERIDES_TYPE_ID, 'Fullerides', $productGroup);
        $this->type(self::CARBON_POLYMERS_TYPE_ID, 'Carbon Polymers', $materialGroup);
        $this->type(self::PLATINUM_TECHNITE_TYPE_ID, 'Platinum Technite', $materialGroup);
        $this->type(self::NITROGEN_FUEL_BLOCK_TYPE_ID, 'Nitrogen Fuel Block', $fuelBlockGroup);

        $reaction = IndustryActivityType::Reaction->value;
        $blueprint = self::FULLERIDES_BLUEPRINT_TYPE_ID;
        $this->em->persist((new IndustryBlueprint())->setTypeId($blueprint)->setMaxProductionLimit(100));
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprint)->setActivityId($reaction)->setProductTypeId(self::FULLERIDES_TYPE_ID)->setQuantity(self::FULLERIDES_OUTPUT_PER_RUN));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprint)->setActivityId($reaction)->setTime(self::FULLERIDES_SECONDS_PER_RUN));
        foreach ([
            self::NITROGEN_FUEL_BLOCK_TYPE_ID => 5,
            self::PLATINUM_TECHNITE_TYPE_ID => 100,
            self::CARBON_POLYMERS_TYPE_ID => 100,
        ] as $materialTypeId => $quantityPerRun) {
            $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprint)->setActivityId($reaction)->setMaterialTypeId($materialTypeId)->setQuantity($quantityPerRun));
        }
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = $this->em->find(InvGroup::class, $groupId) ?? (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group)->setVolume(0.1));
    }

    private function canonical(array $payload): array
    {
        if (!array_is_list($payload)) {
            ksort($payload);
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->canonical($value) : $value, $payload);
    }
}
