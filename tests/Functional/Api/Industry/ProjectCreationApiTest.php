<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryProject;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Tests\Functional\Api\GroupIndustry\BuildsIshtarGroupProject;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-362')]
final class ProjectCreationApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ALPHA_HULL = 990_362_001;
    private const int BETA_HULL = 990_362_002;
    private const int ALPHA_BLUEPRINT = 990_362_011;
    private const int BETA_BLUEPRINT = 990_362_012;
    private const int FIXTURE_ORE = 990_362_101;
    private const int FIXTURE_ALLOY = 990_362_102;
    private const int MANUFACTURING_ACTIVITY = 1;
    private const int MAXIMUM_PRODUCTS = 10;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedFixtureSde();
        $this->seedJitaSellOrderBooks([
            self::FIXTURE_ORE => [['price' => 12.5, 'volume' => 1_000_000]],
            self::FIXTURE_ALLOY => [['price' => 200.0, 'volume' => 100_000]],
        ]);
    }

    public function testCreationWithoutThePrivateFlagGivesADraftPrivateProjectOwnedByTheCreatorWithItsBom(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Alpha run',
            'items' => [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $project = $this->responseJson();
        self::assertSame(['Alpha run', 'draft', true, 'owner', 'accepted'], [$project['name'], $project['status'], $project['private'], $project['myRole'], $project['myStatus']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $project['shortLinkCode']);
        self::assertSame(
            [['Aria Solberg', 'owner', 'accepted']],
            $this->participantRowsOf($project['id']),
        );
        self::assertSame(
            [
                [self::FIXTURE_ORE, 450, 12.5, false, null, null, null, null, null],
                [self::FIXTURE_ALLOY, 45, 200.0, false, null, null, null, null, null],
                [self::ALPHA_HULL, 5, null, true, 'final', 'manufacturing', 5, 10, 20],
            ],
            $this->bomRowsOf($project['id']),
        );
    }

    public function testCreationOfAnOpenProjectOfTwoProductsGivesTheAggregatedBom(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Two hulls',
            'private' => false,
            'items' => [
                ['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
                ['typeId' => self::BETA_HULL, 'typeName' => 'Fixture Beta Hull', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 3],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $project = $this->responseJson();
        self::assertSame(['draft', false], [$project['status'], $project['private']]);
        self::assertSame(
            [[self::ALPHA_HULL, 5, 10, 20], [self::BETA_HULL, 3, 0, 0]],
            array_map(
                static fn (array $item): array => [$item['typeId'], $item['runs'], $item['meLevel'], $item['teLevel']],
                $project['items'],
            ),
        );
        self::assertSame(
            [
                [self::FIXTURE_ORE, 600, 12.5, false, null, null, null, null, null],
                [self::FIXTURE_ALLOY, 45, 200.0, false, null, null, null, null, null],
                [self::ALPHA_HULL, 5, null, true, 'final', 'manufacturing', 5, 10, 20],
                [self::BETA_HULL, 3, null, true, 'final', 'manufacturing', 3, 0, 0],
            ],
            $this->bomRowsOf($project['id']),
        );
    }

    #[DataProvider('invalidProductLists')]
    public function testCreationWithoutProductOrWithTooManyCreatesNoProject(array $items): void
    {
        $projectsBefore = $this->em->getRepository(IndustryProject::class)->count([]);
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'Invalid', 'items' => $items]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['items'], $this->violatedPaths());
        self::assertSame($projectsBefore, $this->em->getRepository(IndustryProject::class)->count([]));
    }

    public static function invalidProductLists(): iterable
    {
        yield 'no product' => [[]];
        yield 'eleven products' => [array_fill(0, self::MAXIMUM_PRODUCTS + 1, ['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1])];
    }

    private function participantRowsOf(string $projectId): array
    {
        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.$projectId.'/members');
        self::assertResponseStatusCodeSame(200);

        return array_map(
            static fn (array $member): array => [$member['characterName'], $member['role'], $member['status']],
            $this->responseJson(),
        );
    }

    private function bomRowsOf(string $projectId): array
    {
        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.$projectId.'/bom');
        self::assertResponseStatusCodeSame(200);

        return array_map(
            static fn (array $line): array => [
                $line['typeId'],
                $line['requiredQuantity'],
                isset($line['estimatedPrice']) ? (float) $line['estimatedPrice'] : null,
                $line['isJob'],
                $line['jobGroup'] ?? null,
                $line['activityType'] ?? null,
                $line['runs'] ?? null,
                $line['meLevel'] ?? null,
                $line['teLevel'] ?? null,
            ],
            $this->rowsSortedBy('isJob', $this->rowsSortedBy('typeId', $this->responseJson())),
        );
    }

    private function seedFixtureSde(): void
    {
        $category = (new InvCategory())->setCategoryId(990_362_900)->setCategoryName('Fixture Category 362')->setPublished(true);
        $group = (new InvGroup())->setGroupId(990_362_901)->setGroupName('Fixture Group 362')->setCategory($category)->setPublished(true);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach ([
            self::ALPHA_HULL => 'Fixture Alpha Hull',
            self::BETA_HULL => 'Fixture Beta Hull',
            self::FIXTURE_ORE => 'Fixture Ore',
            self::FIXTURE_ALLOY => 'Fixture Alloy',
        ] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setPublished(true));
        }
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        foreach ([self::ALPHA_BLUEPRINT => [self::ALPHA_HULL, [self::FIXTURE_ORE => 100, self::FIXTURE_ALLOY => 10]], self::BETA_BLUEPRINT => [self::BETA_HULL, [self::FIXTURE_ORE => 50]]] as $blueprintTypeId => [$productTypeId, $materials]) {
            $connection->insert('sde_industry_blueprints', ['type_id' => $blueprintTypeId, 'max_production_limit' => 200]);
            $connection->insert('sde_industry_activities', ['type_id' => $blueprintTypeId, 'activity_id' => self::MANUFACTURING_ACTIVITY, 'time' => 900]);
            $connection->insert('sde_industry_activity_products', ['type_id' => $blueprintTypeId, 'activity_id' => self::MANUFACTURING_ACTIVITY, 'product_type_id' => $productTypeId, 'quantity' => 1]);
            foreach ($materials as $materialTypeId => $baseQuantityPerRun) {
                $connection->insert('sde_industry_activity_materials', ['type_id' => $blueprintTypeId, 'activity_id' => self::MANUFACTURING_ACTIVITY, 'material_type_id' => $materialTypeId, 'quantity' => $baseQuantityPerRun]);
            }
        }
    }
}
