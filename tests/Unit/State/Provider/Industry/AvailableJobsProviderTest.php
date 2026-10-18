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

    /**
     * @param list<CachedIndustryJob> $jobs
     */
    private function provider(User $pilot, IndustryProject $project, array $jobs): AvailableJobsProvider
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
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
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
            ->setStatus('active')
            ->setStartDate($start)
            ->setEndDate($start->modify('+2 days'));
    }
}
