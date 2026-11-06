<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\AvailableJobResource;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\State\Provider\Industry\AvailableJobsProvider;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AvailableJobsProvider::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class AvailableJobsProviderTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const int ISHTAR_JOB_ID = 7_362_601;
    private const int RAM_JOB_ID = 7_362_602;
    private const int CANCELLED_RAM_JOB_ID = 7_362_603;

    private array $cachedJobs = [];

    private array $jobLookups = [];

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
    }

    public function testListsTheJobsOfTheOwnerOnTheProducersOfTheJobLinesWithTheLineTheyAreLinkedTo(): void
    {
        $this->job($this->ownerMainCharacter, self::ISHTAR_JOB_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 5, 1_482_350.25, '2026-11-02T08:00:00+00:00');
        $this->job($this->ownerAltCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $ramMatch = (new IndustryProjectJobMatch())->setEsiJobId(self::RAM_JOB_ID)->setRuns(4)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($ramMatch, Uuid::v4());
        $this->ramLine->addJobMatch($ramMatch);

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame(
            [
                [self::RAM_JOB_ID, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 4, 86_250.5, 'Aria Alt', $this->ramLine->getId()->toRfc4122(), 'R.A.M.- Starship Tech', $ramMatch->getId()->toRfc4122()],
                [self::ISHTAR_JOB_ID, self::ISHTAR_TYPE_ID, 'Ishtar', 5, 1_482_350.25, 'Aria Solberg', null, null, null],
            ],
            array_map(static fn (AvailableJobResource $job): array => [
                $job->esiJobId,
                $job->productTypeId,
                $job->productTypeName,
                $job->runs,
                $job->cost,
                $job->characterName,
                $job->linkedToBomLineId,
                $job->linkedToBomLineName,
                $job->matchId,
            ], $jobs),
        );
        self::assertCount(1, $this->jobLookups);
        $blueprintTypeIds = $this->jobLookups[0][0];
        sort($blueprintTypeIds);
        self::assertSame(
            [[self::RAM_BLUEPRINT_TYPE_ID, self::ISHTAR_BLUEPRINT_TYPE_ID], [$this->ownerMainCharacter->getId(), $this->ownerAltCharacter->getId()], '2026-11-01T10:00:00+00:00'],
            [$blueprintTypeIds, $this->jobLookups[0][1], $this->jobLookups[0][2]?->format('c')],
        );
    }

    public function testJobsWithoutOutputAreLeftOut(): void
    {
        $this->job($this->ownerMainCharacter, self::RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-03T08:00:00+00:00');
        $this->job($this->ownerMainCharacter, self::CANCELLED_RAM_JOB_ID, self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID, 4, 86_250.5, '2026-11-02T08:00:00+00:00', 'cancelled');

        $jobs = $this->availableJobsFor($this->owner, $this->ishtarProject);

        self::assertSame([self::RAM_JOB_ID], array_map(static fn (AvailableJobResource $job): int => $job->esiJobId, $jobs));
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDenied(): void
    {
        self::assertSame(
            [self::OWNER_ONLY, []],
            [$this->outcomeOf(fn () => $this->availableJobsFor($this->admin, $this->ishtarProject)), $this->jobLookups],
        );
    }

    private function availableJobsFor(User $user, IndustryProject $project): array
    {
        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findByBlueprintsAndCharacters')->willReturnCallback(
            function (array $blueprintTypeIds, array $characterIds, ?\DateTimeImmutable $startedAfter = null): array {
                $this->jobLookups[] = [array_values($blueprintTypeIds), $characterIds, $startedAfter];

                return $this->cachedJobs;
            },
        );
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => (new IndustryActivityProduct())
                ->setTypeId($productTypeId === self::ISHTAR_TYPE_ID ? self::ISHTAR_BLUEPRINT_TYPE_ID : self::RAM_BLUEPRINT_TYPE_ID)
                ->setActivityId(IndustryActivityType::Manufacturing->value)
                ->setProductTypeId($productTypeId)
                ->setQuantity(1),
        );
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveTypeName')->willReturnCallback(
            static fn (int $typeId): ?string => [self::ISHTAR_TYPE_ID => 'Ishtar', self::RAM_TYPE_ID => 'R.A.M.- Starship Tech'][$typeId] ?? null,
        );

        return (new AvailableJobsProvider(
            $this->securityOf($user),
            $this->projectRepository(),
            $jobRepository,
            $productRepository,
            $calculationService,
            $this->accessChecker(),
        ))->provide(new GetCollection(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    private function job(Character $installer, int $jobId, int $blueprintTypeId, int $productTypeId, int $runs, float $cost, string $startDate, string $status = 'active'): void
    {
        $start = new \DateTimeImmutable($startDate);
        $this->cachedJobs[] = (new CachedIndustryJob())
            ->setCharacter($installer)
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
