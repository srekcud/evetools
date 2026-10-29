<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

trait BuildsProjectsOfTheOldRootCalculationOnGoldenSde
{
    use BuildsProjectOnGoldenSde;
    use CreatesUserWithId;

    private const string PILOT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000000001';
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 1_004_051;
    private const int NITROGEN_FUEL_BLOCK_RUNS = 25;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int HYDROGEN_FUEL_BLOCK_BLUEPRINT = 1_004_246;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 1_016_673;
    private const int FERNITE_CARBIDE_RUNS = 10;
    private const int CERAMIC_POWDER = 16660;
    private const int CERAMIC_POWDER_REACTION_FORMULA = 1_016_660;
    private const int FERNITE_ALLOY = 16656;
    private const int FERNITE_ALLOY_REACTION_FORMULA = 1_016_656;
    private const int SCORPION = 640;
    private const int ROBOTICS = 9848;
    private const int PROJECT_ME_LEVEL = 10;
    private const int PROJECT_TE_LEVEL = 20;
    private const float MAX_JOB_DURATION_DAYS = 2.0;

    private array $entityManagerCalls = [];

    private array $publishedUpdates = [];

    private bool $pilotOwnsTataraAndRaitaru = false;

    private User $pilot;

    private EntityManagerInterface $entityManager;

    private IndustryCalculationService $calculationService;

    private IndustryProjectFactory $factory;

    private function buildFactoryOnGoldenSde(): void
    {
        $this->pilot = $this->userWithId(self::PILOT_ID);
        $bonusService = new TataraAndRaitaruBonusService(
            fn (): bool => $this->pilotOwnsTataraAndRaitaru,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $this->entityManager = $this->entityManagerRecordingWrites();
        $this->calculationService = $this->calculationServiceOnGoldenSde($bonusService, $this->createStub(IndustryStructureConfigRepository::class));
        $this->factory = $this->projectFactoryOnGoldenSde($bonusService, $this->calculationService, $this->entityManager, mercureHub: $this->mercureHubRecordingUpdates());
    }

    private function nitrogenFuelBlockProjectOfTheOldCalculation(string $projectId): IndustryProject
    {
        $project = $this->factory->createProject($this->pilot, self::NITROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK_RUNS, self::PROJECT_ME_LEVEL, self::MAX_JOB_DURATION_DAYS, self::PROJECT_TE_LEVEL);
        $this->rootJobsOf($project)[0]->setRuns(1)->setQuantity(self::NITROGEN_FUEL_BLOCK_RUNS);

        return $this->identified($project, $projectId);
    }

    private function ferniteCarbideProjectOfTheOldCalculation(string $projectId): IndustryProject
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->factory->createProject($this->pilot, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS, self::PROJECT_ME_LEVEL, self::MAX_JOB_DURATION_DAYS, self::PROJECT_TE_LEVEL);
        $this->rootJobsOf($project)[0]->setRuns(1)->setQuantity(self::FERNITE_CARBIDE_RUNS);

        return $this->identified($project, $projectId);
    }

    private function identified(IndustryProject $project, string $projectId): IndustryProject
    {
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::fromString($projectId));
        $this->entityManagerCalls = [];

        return $project;
    }

    private function rootJobsOf(IndustryProject $project): array
    {
        return array_values(array_filter(
            $this->jobsOfProduct($project, $project->getProductTypeId()),
            static fn (IndustryProjectStep $job): bool => $job->getDepth() === 0,
        ));
    }

    private function jobsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        $jobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId && $step->getActivityType() !== 'copy',
        ));
        usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $jobs;
    }

    private function entityManagerRecordingWrites(): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('isOpen')->willReturn(true);
        foreach (['persist', 'remove', 'flush', 'clear'] as $write) {
            $entityManager->method($write)->willReturnCallback(function () use ($write): void {
                $this->entityManagerCalls[] = $write;
            });
        }

        return $entityManager;
    }

    private function mercureHubRecordingUpdates(): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'published';
        });

        return $hub;
    }

    private function writesOfTheEntityManager(): array
    {
        return array_values(array_intersect($this->entityManagerCalls, ['persist', 'remove', 'flush']));
    }

    private function clearCountOfTheEntityManager(): int
    {
        return \count(array_keys($this->entityManagerCalls, 'clear', true));
    }

    private function topicsAndStatusesOfThePublishedUpdates(): array
    {
        return array_map(
            static fn (Update $update): array => [$update->getTopics()[0], json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR)['status']],
            $this->publishedUpdates,
        );
    }
}
