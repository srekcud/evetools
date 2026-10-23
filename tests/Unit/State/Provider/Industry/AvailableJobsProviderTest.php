<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use App\State\Provider\Industry\AvailableJobsProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AvailableJobsProvider::class)]
#[Group('issue-115')]
final class AvailableJobsProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-115000000001';
    private const string ROBOTICS_STEP_ID = '0192f0c4-7b1e-7a3c-9d2e-115000009848';
    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID = 4314;
    private const int ROBOTICS_JOB_ID = 700_009_848;
    private const int NITROGEN_FUEL_BLOCK_JOB_ID = 700_004_051;
    private const int MANUFACTURING_BLUEPRINT_TYPE_ID = 1001;
    private const int CANCELLED_JOB_ID = 300;
    private const int REVERTED_JOB_ID = 301;
    private const int DELIVERED_JOB_ID = 305;
    private const int ACTIVE_JOB_ID = 308;
    private const int RIFTER_BLUEPRINT_TYPE_ID = 1001;
    private const string RIFTER_BLUEPRINT_NAME = 'Rifter Blueprint';
    private const int RIFTER_TYPE_ID = 2001;
    private const string RIFTER_NAME = 'Rifter';
    private const int MANUFACTURING_JOB_ID = 500;
    private const int ME_RESEARCH_JOB_ID = 501;

    public function testJobProducingATypeMissingFromTheSdeIsListedWithAnUnknownTypeNameAndStaysLinkedToItsStep(): void
    {
        $pilot = new User();
        $character = (new Character())->setName('Aurora Industrialist')->setUser($pilot);
        $pilot->addCharacter($character);

        $project = (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId(self::ROBOTICS_TYPE_ID)
            ->setRuns(30);
        $roboticsStep = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::ROBOTICS_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::ROBOTICS_TYPE_ID)
            ->setQuantity(30)
            ->setRuns(30)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(0);
        (new \ReflectionProperty(IndustryProjectStep::class, 'id'))->setValue($roboticsStep, Uuid::fromString(self::ROBOTICS_STEP_ID));
        $project->addStep($roboticsStep);
        $roboticsStep->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(self::ROBOTICS_JOB_ID)->setRuns(30)->setStatus('active'));

        $roboticsJob = $this->job($character, self::ROBOTICS_JOB_ID, self::ROBOTICS_BLUEPRINT_TYPE_ID, self::ROBOTICS_TYPE_ID, 30, 1_250_000.0, '2026-10-17T08:00:00+00:00');
        $nitrogenFuelBlockJob = $this->job($character, self::NITROGEN_FUEL_BLOCK_JOB_ID, self::NITROGEN_FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 40, 320_000.0, '2026-10-16T08:00:00+00:00');

        $jobs = $this->provider($pilot, $project, [$nitrogenFuelBlockJob, $roboticsJob])
            ->provide(new GetCollection(), ['id' => self::PROJECT_ID]);

        self::assertCount(2, $jobs);
        self::assertSame(self::ROBOTICS_JOB_ID, $jobs[0]->esiJobId);
        self::assertSame(self::ROBOTICS_TYPE_ID, $jobs[0]->productTypeId);
        self::assertNull($jobs[0]->productTypeName);
        self::assertSame(30, $jobs[0]->runs);
        self::assertSame(1_250_000.0, $jobs[0]->cost);
        self::assertSame(self::ROBOTICS_STEP_ID, $jobs[0]->linkedToStepId);
        self::assertNull($jobs[0]->linkedToStepName);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_JOB_ID, $jobs[1]->esiJobId);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $jobs[1]->productTypeName);
        self::assertNull($jobs[1]->linkedToStepId);
    }

    #[Group('issue-188')]
    public function testAvailableJobsExcludeJobsWithoutOutput(): void
    {
        $pilot = new User();
        $character = (new Character())->setName('Aurora Industrialist')->setUser($pilot);
        $pilot->addCharacter($character);
        $project = (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setRuns(10);
        $project->addStep((new IndustryProjectStep())
            ->setBlueprintTypeId(self::MANUFACTURING_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setQuantity(400)
            ->setRuns(10)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(0));

        $jobs = $this->provider($pilot, $project, [
            $this->job($character, self::ACTIVE_JOB_ID, self::MANUFACTURING_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1_250_000.75, '2026-10-20T08:00:00+00:00'),
            $this->job($character, self::DELIVERED_JOB_ID, self::MANUFACTURING_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1_250_000.75, '2026-10-19T08:00:00+00:00', 'delivered'),
            $this->job($character, self::REVERTED_JOB_ID, self::MANUFACTURING_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1_250_000.75, '2026-10-18T08:00:00+00:00', 'reverted'),
            $this->job($character, self::CANCELLED_JOB_ID, self::MANUFACTURING_BLUEPRINT_TYPE_ID, self::NITROGEN_FUEL_BLOCK_TYPE_ID, 10, 1_250_000.75, '2026-10-17T08:00:00+00:00', 'cancelled'),
        ])->provide(new GetCollection(), ['id' => self::PROJECT_ID]);

        self::assertSame(
            [[self::ACTIVE_JOB_ID, 'active'], [self::DELIVERED_JOB_ID, 'delivered']],
            array_map(static fn ($availableJob): array => [$availableJob->esiJobId, $availableJob->status], $jobs),
        );
    }

    #[Group('issue-214')]
    public function testJobWithoutCostAndProductIsListedWithUnknownCostAndItsBlueprintAsDisplayedType(): void
    {
        $pilot = new User();
        $character = (new Character())->setName('Aurora Industrialist')->setUser($pilot);
        $pilot->addCharacter($character);
        $project = (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId(self::RIFTER_TYPE_ID)
            ->setRuns(10);
        $project->addStep((new IndustryProjectStep())
            ->setBlueprintTypeId(self::RIFTER_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::RIFTER_TYPE_ID)
            ->setQuantity(10)
            ->setRuns(10)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(0));
        $manufacturingJob = $this->job($character, self::MANUFACTURING_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, self::RIFTER_TYPE_ID, 10, 1_250_000.75, '2026-10-21T08:00:00+00:00');
        $meResearchJob = $this->job($character, self::ME_RESEARCH_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, self::RIFTER_TYPE_ID, 2, 1.0, '2026-10-20T08:00:00+00:00')
            ->setActivityId(4)
            ->setProductTypeId(null)
            ->setCost(null);

        $jobs = $this->provider($pilot, $project, [$meResearchJob, $manufacturingJob], $this->calculationServiceOnSdeKnowing([
            self::RIFTER_BLUEPRINT_TYPE_ID => self::RIFTER_BLUEPRINT_NAME,
            self::RIFTER_TYPE_ID => self::RIFTER_NAME,
        ]))->provide(new GetCollection(), ['id' => self::PROJECT_ID]);

        self::assertSame(
            [
                [self::MANUFACTURING_JOB_ID, self::RIFTER_TYPE_ID, self::RIFTER_NAME, 10, 1_250_000.75],
                [self::ME_RESEARCH_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, self::RIFTER_BLUEPRINT_NAME, 2, null],
            ],
            array_map(
                static fn ($availableJob): array => [$availableJob->esiJobId, $availableJob->productTypeId, $availableJob->productTypeName, $availableJob->runs, $availableJob->cost],
                $jobs,
            ),
        );
    }

    /**
     * @param list<CachedIndustryJob> $jobs
     */
    private function provider(User $pilot, IndustryProject $project, array $jobs, ?IndustryCalculationService $calculationService = null): AvailableJobsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findByBlueprintsAndCharacters')->willReturn($jobs);

        return new AvailableJobsProvider(
            $security,
            $projectRepository,
            $jobRepository,
            $calculationService ?? $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
        );
    }

    private function job(
        Character $character,
        int $jobId,
        int $blueprintTypeId,
        int $productTypeId,
        int $runs,
        float $cost,
        string $startDate,
        string $status = 'active',
    ): CachedIndustryJob {
        $start = new \DateTimeImmutable($startDate);

        return (new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId($jobId)
            ->setActivityId(1)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setCost($cost)
            ->setStatus($status)
            ->setStartDate($start)
            ->setEndDate($start->modify('+2 days'));
    }
}
