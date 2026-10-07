<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\ProjectListResource;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\ProjectCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Middleware\Debug\Connection as DebugConnection;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #32: listing a user's Industry projects must not lazy-load steps and job matches
 * project by project (1 + P + S queries). The number of queries on the industry tables
 * must stay bounded whatever the number of projects and steps, and the listed values
 * must stay exactly the same.
 *
 * The SDE is empty in the test database: type names resolve to "Type #<id>".
 */
final class ProjectCollectionProviderTest extends IntegrationTestCase
{
    /** projects + steps + job matches, whatever the number of projects and steps */
    private const int MAX_INDUSTRY_QUERIES = 3;

    /** Industry tables read when listing projects (SDE tables are prefixed `sde_` and excluded). */
    private const string INDUSTRY_TABLES_PATTERN = '/(?<!sde_)\bindustry_(projects|project_steps|step_job_matches|step_purchases)\b/';

    private const int SABRE_TYPE_ID = 22456;
    private const int SABRE_BLUEPRINT_TYPE_ID = 22457;
    private const int JACKDAW_TYPE_ID = 34828;
    private const int JACKDAW_BLUEPRINT_TYPE_ID = 34829;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int COMPONENT_TYPE_ID = 11399;
    private const int COMPONENT_BLUEPRINT_TYPE_ID = 11400;
    private const int TRITANIUM_TYPE_ID = 34;

    private User $pilot;
    private string $sabreProjectId;
    private string $jackdawProjectId;
    private string $ishtarProjectId;
    private string $otherPilotProjectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pilot = $this->createUser();
        $this->sabreProjectId = $this->createSabreProject($this->pilot);
        $this->jackdawProjectId = $this->createJackdawProject($this->pilot);
        $this->ishtarProjectId = $this->createIshtarPersonalUseProject($this->pilot);
        $this->otherPilotProjectId = $this->createSabreProject($this->createUser());
        $this->flushAndClear();
    }

    public function testListingProjectsQueriesIndustryTablesABoundedNumberOfTimes(): void
    {
        $industryQueries = array_values(array_filter(
            $this->sqlExecutedDuring(fn () => $this->listProjectsAs($this->pilot)),
            static fn (string $sql): bool => preg_match(self::INDUSTRY_TABLES_PATTERN, $sql) === 1,
        ));

        self::assertLessThanOrEqual(
            self::MAX_INDUSTRY_QUERIES,
            \count($industryQueries),
            sprintf(
                "3 projects x 4 steps: expected at most %d queries on industry tables, got %d:\n%s",
                self::MAX_INDUSTRY_QUERIES,
                \count($industryQueries),
                implode("\n", $industryQueries),
            ),
        );
    }

    public function testListingReturnsOnlyTheUserProjects(): void
    {
        $projects = $this->projectsById($this->listProjectsAs($this->pilot));

        self::assertEqualsCanonicalizing(
            [$this->sabreProjectId, $this->jackdawProjectId, $this->ishtarProjectId],
            array_keys($projects),
        );
        self::assertArrayNotHasKey($this->otherPilotProjectId, $projects);
    }

    public function testSabreProjectSumsJobMatchCostsAndComputesProfitFromRealSellPrice(): void
    {
        $sabre = $this->projectsById($this->listProjectsAs($this->pilot))[$this->sabreProjectId];

        // 5M + 3M (root step) + 1.5M (component step), the match without cost is ignored
        self::assertSame(9_500_000.0, $sabre->jobsCost);
        // material 400M + jobs 9.5M + tax 10M
        self::assertSame(419_500_000.0, $sabre->totalCost);
        self::assertSame(480_500_000.0, $sabre->profit);
        self::assertSame('Type #' . self::SABRE_TYPE_ID, $sabre->displayName);
        self::assertSame(10, $sabre->runs);
        // The copy step at depth 0 is not a root product
        self::assertSame([[
            'typeId' => self::SABRE_TYPE_ID,
            'typeName' => 'Type #' . self::SABRE_TYPE_ID,
            'runs' => 10,
            'meLevel' => 2,
            'teLevel' => 4,
            'count' => 1,
        ]], $sabre->rootProducts);
    }

    public function testJackdawProjectAggregatesSplitRootStepsAndFallsBackToEstimates(): void
    {
        $jackdaw = $this->projectsById($this->listProjectsAs($this->pilot))[$this->jackdawProjectId];

        // Real jobs cost (2M) wins over the estimated 7M because it is > 0
        self::assertSame(2_000_000.0, $jackdaw->jobsCost);
        // estimated material 200M + jobs 2M
        self::assertSame(202_000_000.0, $jackdaw->totalCost);
        // estimated sell price 300M - 202M
        self::assertSame(98_000_000.0, $jackdaw->profit);
        self::assertSame('Jackdaw batch', $jackdaw->displayName);
        self::assertSame([[
            'typeId' => self::JACKDAW_TYPE_ID,
            'typeName' => 'Type #' . self::JACKDAW_TYPE_ID,
            'runs' => 5,
            'meLevel' => 10,
            'teLevel' => 20,
            'count' => 2,
        ]], $jackdaw->rootProducts);
    }

    public function testPersonalUseProjectKeepsEntityProfit(): void
    {
        $ishtar = $this->projectsById($this->listProjectsAs($this->pilot))[$this->ishtarProjectId];

        self::assertSame(4_000_000.0, $ishtar->jobsCost);
        // material 150M + jobs 4M
        self::assertSame(154_000_000.0, $ishtar->totalCost);
        // sell price 250M - 154M, taken from IndustryProject::getProfit()
        self::assertSame(96_000_000.0, $ishtar->profit);
        self::assertTrue($ishtar->personalUse);
    }

    public function testTotalProfitSumsEntityProfitOfEachProject(): void
    {
        $list = $this->listProjectsAs($this->pilot);

        // CARACTÉRISATION : comportement actuel, suspecté faux (hors périmètre de l'issue #32) :
        // le total ignore le profit estimé du Jackdaw (98M) affiché par ligne, car il somme
        // IndustryProject::getProfit() qui vaut null sans sellPrice réel. Sabre 480.5M + Ishtar 96M.
        self::assertSame(576_500_000.0, $list->totalProfit);
    }

    private function listProjectsAs(User $user): ProjectListResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new ProjectCollectionProvider(
            $security,
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(IndustryResourceMapper::class),
        );

        $list = $provider->provide(new GetCollection());
        \assert($list instanceof ProjectListResource);

        return $list;
    }

    /** @return array<string, ProjectResource> */
    private function projectsById(ProjectListResource $list): array
    {
        $byId = [];
        foreach ($list->projects as $project) {
            $byId[$project->id] = $project;
        }

        return $byId;
    }

    /**
     * Wraps the already open driver connection with Symfony's debug middleware for the
     * duration of the callback. The profiler is not installed, so `doctrine.debug_data_holder`
     * does not exist in the test container; swapping the driver connection keeps the
     * dama transaction and the fixtures visible.
     *
     * @return list<string> SQL executed during the callback
     */
    private function sqlExecutedDuring(callable $callback): array
    {
        $connection = $this->em->getConnection();
        $connection->getNativeConnection(); // force the driver connection to be open

        $driverConnectionProperty = new \ReflectionProperty(Connection::class, '_conn');
        $driverConnection = $driverConnectionProperty->getValue($connection);
        $debugDataHolder = new DebugDataHolder();
        $driverConnectionProperty->setValue(
            $connection,
            new DebugConnection($driverConnection, $debugDataHolder, null, 'default'),
        );

        try {
            $callback();
        } finally {
            $driverConnectionProperty->setValue($connection, $driverConnection);
        }

        return array_map(
            static fn ($query): string => $query['sql'],
            $debugDataHolder->getData()['default'] ?? [],
        );
    }

    private function createSabreProject(User $owner): string
    {
        $project = $this->createProject($owner, self::SABRE_TYPE_ID, runs: 10)
            ->setMaterialCost(400_000_000.0)
            ->setTaxAmount(10_000_000.0)
            ->setSellPrice(900_000_000.0);

        $root = $this->addStep($project, self::SABRE_BLUEPRINT_TYPE_ID, self::SABRE_TYPE_ID, depth: 0, runs: 10, quantity: 10)
            ->setMeLevel(2)
            ->setTeLevel(4);
        $this->addJobMatch($root, esiJobId: 700_000_001, runs: 6, cost: 5_000_000.0);
        $this->addJobMatch($root, esiJobId: 700_000_002, runs: 4, cost: 3_000_000.0);

        $component = $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, depth: 1, runs: 2, quantity: 200);
        $this->addJobMatch($component, esiJobId: 700_000_003, runs: 2, cost: 1_500_000.0);
        $this->addPurchase($component, quantity: 50_000, unitPrice: 4.0);

        $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID + 2, self::COMPONENT_TYPE_ID + 2, depth: 1, runs: 1, quantity: 100);

        $copy = $this->addStep($project, self::SABRE_BLUEPRINT_TYPE_ID, self::SABRE_BLUEPRINT_TYPE_ID, depth: 0, runs: 10, quantity: 10)
            ->setActivityType('copy');
        $this->addJobMatch($copy, esiJobId: 700_000_004, runs: 10, cost: null);

        return $this->idOf($project);
    }

    private function createJackdawProject(User $owner): string
    {
        $project = $this->createProject($owner, self::JACKDAW_TYPE_ID, runs: 5)
            ->setName('Jackdaw batch')
            ->setEstimatedMaterialCost(200_000_000.0)
            ->setEstimatedJobCost(7_000_000.0)
            ->setEstimatedSellPrice(300_000_000.0)
            ->setEstimatedSellPriceSource('jita');

        $firstSplit = $this->addStep($project, self::JACKDAW_BLUEPRINT_TYPE_ID, self::JACKDAW_TYPE_ID, depth: 0, runs: 3, quantity: 3)
            ->setSplitGroupId('8c1e0a4e-4f7b-4a51-9d55-2b8f6f0f3a11')
            ->setSplitIndex(0)
            ->setTotalGroupRuns(5);
        $this->addJobMatch($firstSplit, esiJobId: 700_000_011, runs: 3, cost: 2_000_000.0);
        $this->addStep($project, self::JACKDAW_BLUEPRINT_TYPE_ID, self::JACKDAW_TYPE_ID, depth: 0, runs: 2, quantity: 2)
            ->setSplitGroupId('8c1e0a4e-4f7b-4a51-9d55-2b8f6f0f3a11')
            ->setSplitIndex(1)
            ->setTotalGroupRuns(5);

        $component = $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, depth: 1, runs: 1, quantity: 100);
        $this->addPurchase($component, quantity: 20_000, unitPrice: 4.5);
        $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID + 2, self::COMPONENT_TYPE_ID + 2, depth: 1, runs: 1, quantity: 100);

        return $this->idOf($project);
    }

    private function createIshtarPersonalUseProject(User $owner): string
    {
        $project = $this->createProject($owner, self::ISHTAR_TYPE_ID, runs: 1)
            ->setPersonalUse(true)
            ->setMaterialCost(150_000_000.0)
            ->setSellPrice(250_000_000.0);

        $root = $this->addStep($project, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, depth: 0, runs: 1, quantity: 1);
        $this->addJobMatch($root, esiJobId: 700_000_021, runs: 1, cost: 4_000_000.0);
        $component = $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, depth: 1, runs: 1, quantity: 100);
        $this->addPurchase($component, quantity: 10_000, unitPrice: 5.0);
        $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID + 2, self::COMPONENT_TYPE_ID + 2, depth: 1, runs: 1, quantity: 100);
        $this->addStep($project, self::COMPONENT_BLUEPRINT_TYPE_ID + 4, self::COMPONENT_TYPE_ID + 4, depth: 2, runs: 1, quantity: 100);

        return $this->idOf($project);
    }

    private function createProject(User $owner, int $productTypeId, int $runs): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($owner)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);
        $this->em->persist($project);

        return $project;
    }

    private function addStep(IndustryProject $project, int $blueprintTypeId, int $productTypeId, int $depth, int $runs, int $quantity): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setDepth($depth)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setSortOrder($project->getSteps()->count());
        $project->addStep($step);

        return $step;
    }

    private function addJobMatch(IndustryProjectStep $step, int $esiJobId, int $runs, ?float $cost): void
    {
        $step->addJobMatch(
            (new IndustryStepJobMatch())
                ->setEsiJobId($esiJobId)
                ->setRuns($runs)
                ->setCost($cost)
                ->setStatus('delivered')
                ->setCharacterName('Test Pilot'),
        );
    }

    private function addPurchase(IndustryProjectStep $step, int $quantity, float $unitPrice): void
    {
        $step->addPurchase(
            (new IndustryStepPurchase())
                ->setTypeId(self::TRITANIUM_TYPE_ID)
                ->setQuantity($quantity)
                ->setUnitPrice($unitPrice)
                ->setTotalPrice($quantity * $unitPrice)
                ->setSource('manual'),
        );
    }

    private function idOf(IndustryProject $project): string
    {
        $this->em->flush();
        $id = $project->getId();
        \assert($id !== null);

        return $id->toRfc4122();
    }
}
