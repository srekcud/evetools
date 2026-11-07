<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-343')]
final class ProjectBuyOrBuildApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_343_001;
    private const int RAM_BLUEPRINT_TYPE_ID = 999_343_002;
    private const int SYNTHETIC_CATEGORY_ID = 999_343_003;
    private const int SYNTHETIC_GROUP_ID = 999_343_004;
    private const int RAM_BASE_QUANTITY_PER_ISHTAR_RUN = 155;
    private const int RAM_UNITS_PER_BLUEPRINT_RUN = 100;
    private const int RAM_TRITANIUM_PER_RUN = 400;
    private const int ISHTAR_TRITANIUM_PER_RUN = 5_000;
    private const int RAM_DEMAND = 298;
    private const int RAM_SURPLUS = 2;

    private const array ROW_KEYS = [
        'activity', 'buildPerUnit', 'buildPerUnitIfSurplusReused', 'buyPerUnit', 'choice', 'choiceWithoutImport',
        'importPerUnit', 'jitaPerUnit', 'jumps', 'missingData', 'producedQuantity', 'quantity', 'runs',
        'saving', 'savingIfSurplusReused', 'surplus', 'typeId', 'typeName', 'volumePerUnit', 'warnings',
    ];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(self::RAM_STARSHIP_TECH_TYPE_ID, self::TRITANIUM_TYPE_ID);
    }

    public function testAcceptedParticipantReadsTheBuyOrBuildRowOfTheComponentWithUnknownValuesAsNull(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122(), 150.0, 1], [$body['id'], $body['importIskPerCubicMeterJump'], \count($body['rows'])]);
        $row = $body['rows'][0];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(self::ROW_KEYS, $keys);
        self::assertSame(
            [
                self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 'manufacturing',
                self::RAM_DEMAND, self::RAM_RUNS, self::RAM_RUNS * self::RAM_UNITS_PER_RUN, self::RAM_SURPLUS,
                null, null, null, null, null, null, null,
                'unknown', 'unknown',
            ],
            [
                $row['typeId'], $row['typeName'], $row['activity'],
                $row['quantity'], $row['runs'], $row['producedQuantity'], $row['surplus'],
                $row['volumePerUnit'], $row['jumps'], $row['buildPerUnit'], $row['jitaPerUnit'], $row['importPerUnit'], $row['buyPerUnit'], $row['saving'],
                $row['choice'], $row['choiceWithoutImport'],
            ],
        );
    }

    public function testPilotOfAnotherCorporationIsRefusedTheBuyOrBuild(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->buyOrBuildUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testBuyOrBuildOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->buyOrBuildUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function buyOrBuildUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/buy-or-build';
    }

    private function seedIshtarBlueprintsAndTheProjectProduct(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SYNTHETIC_CATEGORY_ID)->setCategoryName('Ship');
        $group = (new InvGroup())->setGroupId(self::SYNTHETIC_GROUP_ID)->setGroupName('Heavy Assault Cruiser')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach ([
            self::ISHTAR_TYPE_ID => 'Ishtar',
            self::RAM_STARSHIP_TECH_TYPE_ID => 'R.A.M.- Starship Tech',
            self::TRITANIUM_TYPE_ID => 'Tritanium',
        ] as $typeId => $typeName) {
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
        $blueprints = [
            self::ISHTAR_BLUEPRINT_TYPE_ID => [self::ISHTAR_TYPE_ID, 1, [self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_BASE_QUANTITY_PER_ISHTAR_RUN, self::TRITANIUM_TYPE_ID => self::ISHTAR_TRITANIUM_PER_RUN]],
            self::RAM_BLUEPRINT_TYPE_ID => [self::RAM_STARSHIP_TECH_TYPE_ID, self::RAM_UNITS_PER_BLUEPRINT_RUN, [self::TRITANIUM_TYPE_ID => self::RAM_TRITANIUM_PER_RUN]],
        ];
        foreach ($blueprints as $blueprintTypeId => [$productTypeId, $unitsPerRun, $materials]) {
            $connection->insert('sde_industry_blueprints', ['type_id' => $blueprintTypeId, 'max_production_limit' => 200]);
            $connection->insert('sde_industry_activities', ['type_id' => $blueprintTypeId, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
            $connection->insert('sde_industry_activity_products', [
                'type_id' => $blueprintTypeId,
                'activity_id' => self::ACTIVITY_MANUFACTURING,
                'product_type_id' => $productTypeId,
                'quantity' => $unitsPerRun,
            ]);
            foreach ($materials as $materialTypeId => $baseQuantityPerRun) {
                $connection->insert('sde_industry_activity_materials', [
                    'type_id' => $blueprintTypeId,
                    'activity_id' => self::ACTIVITY_MANUFACTURING,
                    'material_type_id' => $materialTypeId,
                    'quantity' => $baseQuantityPerRun,
                ]);
            }
        }
    }
}
