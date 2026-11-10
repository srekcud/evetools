<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectTemplate;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Tests\Functional\Api\Industry\Project\BuildsIshtarGroupProject;
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
    private const int MAXIMUM_PRODUCTS = 100;
    private const float MINIMUM_OUTSIDE_SAVING = 125_000.5;

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

    #[Group('issue-346')]
    public function testPersonalUseIsChosenAtCreationAndDefaultsToAProjectMeantToBeSold(): void
    {
        $items = [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]];
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'To sell', 'items' => $items]);
        self::assertResponseStatusCodeSame(201);
        $toSell = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'For me', 'items' => $items, 'personalUse' => true]);
        self::assertResponseStatusCodeSame(201);
        $forMe = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.$forMe['id']);
        self::assertResponseIsSuccessful();
        $read = $this->responseJson();

        self::assertFalse($toSell['personalUse']);
        self::assertTrue($forMe['personalUse']);
        self::assertTrue($read['personalUse']);
    }

    #[Group('issue-50')]
    public function testSellStructureDefaultsToThePreferredOneIsChosenAmongTheMarketStructuresChangedByPatchAndCopiedIntoATemplate(): void
    {
        $this->owner->setPreferredMarketStructureId(60_003_760)->setPreferredMarketStructureName('Jita 4-4')->addMarketStructure(1_042_000_000_001, 'Keepstar A');
        $this->em->flush();
        $items = [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]];
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'Default structure', 'items' => $items]);
        self::assertResponseStatusCodeSame(201);
        $byDefault = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'Chosen structure', 'items' => $items, 'sellStructureId' => 1_042_000_000_001]);
        self::assertResponseStatusCodeSame(201);
        $chosen = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', ['name' => 'Unknown structure', 'items' => $items, 'sellStructureId' => 999]);
        self::assertResponseStatusCodeSame(400);

        $this->actAs($this->owner);
        $this->request('PATCH', '/api/industry/projects/'.$byDefault['id'], ['sellStructureId' => 1_042_000_000_001]);
        self::assertResponseStatusCodeSame(200);
        $patched = $this->responseJson();

        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/project-templates', ['projectId' => $chosen['id'], 'name' => 'Keepstar line', 'sharedWithCorporation' => false]);
        self::assertResponseStatusCodeSame(201);
        $template = $this->responseJson();

        self::assertSame([60_003_760, 'Jita 4-4'], [$byDefault['sellStructureId'], $byDefault['sellStructureName']]);
        self::assertSame([1_042_000_000_001, 'Keepstar A'], [$chosen['sellStructureId'], $chosen['sellStructureName']]);
        self::assertSame([1_042_000_000_001, 'Keepstar A'], [$patched['sellStructureId'], $patched['sellStructureName']]);
        self::assertSame([1_042_000_000_001, 'Keepstar A'], [$template['sellStructureId'], $template['sellStructureName']]);
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

    #[Group('issue-49')]
    public function testCreationWithTwoLinesOfTheSameProductGivesOneFinalJobPerLine(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Two lines of one hull',
            'items' => [
                ['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
                ['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $finalJobs = array_values(array_filter(
            $this->bomRowsOf($this->responseJson()['id']),
            static fn (array $row): bool => true === $row[3] && 'final' === $row[4],
        ));
        self::assertSame(
            [
                [self::ALPHA_HULL, 5, null, true, 'final', 'manufacturing', 5, 10, 20],
                [self::ALPHA_HULL, 5, null, true, 'final', 'manufacturing', 5, 10, 20],
            ],
            $finalJobs,
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

    #[Group('issue-341')]
    public function testCreationAcceptingAStructureOutsideTheFavoriteSystemKeepsTheSelectionPolicyOnTheProject(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Beta anywhere cheaper',
            'acceptsOutsideFavoriteSystem' => true,
            'minimumOutsideSaving' => self::MINIMUM_OUTSIDE_SAVING,
            'items' => [['typeId' => self::BETA_HULL, 'typeName' => 'Fixture Beta Hull', 'meLevel' => 4, 'teLevel' => 8, 'runs' => 7]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $projectId = $this->responseJson()['id'];
        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.$projectId);
        self::assertResponseStatusCodeSame(200);
        $project = $this->responseJson();
        self::assertSame([true, self::MINIMUM_OUTSIDE_SAVING], [$project['acceptsOutsideFavoriteSystem'], $project['minimumOutsideSaving']]);
    }

    #[Group('issue-341')]
    public function testCreationWithANegativeMinimumOutsideSavingCreatesNoProject(): void
    {
        $projectsBefore = $this->em->getRepository(IndustryProject::class)->count([]);
        $this->actAs($this->owner);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Negative saving',
            'acceptsOutsideFavoriteSystem' => true,
            'minimumOutsideSaving' => -1.5,
            'items' => [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 2]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['minimumOutsideSaving'], $this->violatedPaths());
        self::assertSame($projectsBefore, $this->em->getRepository(IndustryProject::class)->count([]));
    }

    #[Group('issue-355')]
    public function testEachProjectCreatedFromASharedTemplateIsLinkedToItAndCountsInItsUsageForTheOwnerAndTheCorpmate(): void
    {
        $alphaHullLine = $this->persistTemplate($this->owner, 'Alpha hull line', true);
        $this->em->flush();

        $corpmateProjectId = $this->createAlphaProjectFromTemplate($this->ownerCorpmate, $alphaHullLine);
        $usageAfterTheCorpmate = [$this->usageCountSeenBy($this->owner, $alphaHullLine), $this->usageCountSeenBy($this->ownerCorpmate, $alphaHullLine)];
        $ownerProjectId = $this->createAlphaProjectFromTemplate($this->owner, $alphaHullLine);
        $usageAfterTheOwner = [$this->usageCountSeenBy($this->owner, $alphaHullLine), $this->usageCountSeenBy($this->ownerCorpmate, $alphaHullLine)];

        $this->em->clear();
        self::assertSame([1, 1], $usageAfterTheCorpmate);
        self::assertSame([2, 2], $usageAfterTheOwner);
        self::assertSame((string) $alphaHullLine->getId(), (string) $this->em->find(IndustryProject::class, $corpmateProjectId)?->getTemplate()?->getId());
        self::assertSame((string) $alphaHullLine->getId(), (string) $this->em->find(IndustryProject::class, $ownerProjectId)?->getTemplate()?->getId());
    }

    #[Group('issue-355')]
    public function testCreationFromAnUnsharedTemplateOfAnotherPilotIsNotFoundAndCreatesNoProject(): void
    {
        $privateLine = $this->persistTemplate($this->owner, 'Private alpha hull line', false);
        $this->em->flush();
        $projectsBefore = $this->em->getRepository(IndustryProject::class)->count([]);

        $this->actAs($this->ownerCorpmate);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Borrowed line',
            'templateId' => (string) $privateLine->getId(),
            'items' => [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Template not found', $this->responseJson()['detail']);
        self::assertSame($projectsBefore, $this->em->getRepository(IndustryProject::class)->count([]));
    }

    #[Group('issue-355')]
    public function testDeletingAUsedTemplateKeepsItsTwoProjectsWithoutLink(): void
    {
        $alphaHullLine = $this->persistTemplate($this->owner, 'Alpha hull line', true);
        $this->em->flush();
        $corpmateProjectId = $this->createAlphaProjectFromTemplate($this->ownerCorpmate, $alphaHullLine);
        $ownerProjectId = $this->createAlphaProjectFromTemplate($this->owner, $alphaHullLine);
        $this->em->clear();
        $linkedTemplateIds = array_map(
            fn (string $projectId): ?string => $this->em->find(IndustryProject::class, $projectId)?->getTemplate()?->getId()->toRfc4122(),
            [$corpmateProjectId, $ownerProjectId],
        );
        self::assertSame([$alphaHullLine->getId()->toRfc4122(), $alphaHullLine->getId()->toRfc4122()], $linkedTemplateIds);

        $this->actAs($this->owner);
        $this->request('DELETE', '/api/industry/project-templates/'.$alphaHullLine->getId());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $corpmateProject = $this->em->find(IndustryProject::class, $corpmateProjectId);
        $ownerProject = $this->em->find(IndustryProject::class, $ownerProjectId);
        self::assertNotNull($corpmateProject);
        self::assertNotNull($ownerProject);
        self::assertNull($corpmateProject->getTemplate());
        self::assertNull($ownerProject->getTemplate());
        self::assertSame('Alpha run', $ownerProject->getName());
    }

    public static function invalidProductLists(): iterable
    {
        yield 'no product' => [[]];
        yield 'one hundred and one products' => [array_fill(0, self::MAXIMUM_PRODUCTS + 1, ['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1])];
    }

    private function persistTemplate(User $owner, string $name, bool $sharedWithCorporation): IndustryProjectTemplate
    {
        $template = (new IndustryProjectTemplate())
            ->setOwner($owner)
            ->setName($name)
            ->setItems([['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]])
            ->setBlacklistGroupIds([])
            ->setBlacklistTypeIds([])
            ->setSharedWithCorporation($sharedWithCorporation);
        $this->em->persist($template);

        return $template;
    }

    private function createAlphaProjectFromTemplate(User $creator, IndustryProjectTemplate $template): string
    {
        $this->actAs($creator);
        $this->request('POST', '/api/industry/projects', [
            'name' => 'Alpha run',
            'templateId' => (string) $template->getId(),
            'items' => [['typeId' => self::ALPHA_HULL, 'typeName' => 'Fixture Alpha Hull', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->responseJson()['id'];
    }

    private function usageCountSeenBy(User $viewer, IndustryProjectTemplate $template): int
    {
        $this->actAs($viewer);
        $this->request('GET', '/api/industry/project-templates');
        self::assertResponseStatusCodeSame(200);
        $seen = array_values(array_filter($this->responseJson(), static fn (array $row): bool => $row['id'] === (string) $template->getId()));
        self::assertCount(1, $seen);

        return $seen[0]['usageCount'];
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
