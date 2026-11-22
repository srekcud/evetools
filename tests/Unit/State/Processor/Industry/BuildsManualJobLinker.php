<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use App\Entity\IndustryProjectJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\UnassignedJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\ManualJobLinker;
use App\Service\Industry\Project\ProjectContainerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

trait BuildsManualJobLinker
{
    private const int VEXOR_BLUEPRINT_TYPE_ID = 12004;

    private array $storedJobMatches = [];

    private array $removedEntities = [];

    private function manualJobLinker(?UnassignedJobRepository $unassignedJobRepository = null): ManualJobLinker
    {
        $jobMatchRepository = $this->createStub(IndustryProjectJobMatchRepository::class);
        $jobMatchRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectJobMatch => array_find(
                $this->storedJobMatches,
                static fn (IndustryProjectJobMatch $match): bool => $match->getEsiJobId() === $criteria['esiJobId'],
            ),
        );

        $jobMatchRepository->method('findByEsiJobIds')->willReturnCallback(
            fn (array $esiJobIds): array => array_values(array_filter(
                $this->storedJobMatches,
                static fn (IndustryProjectJobMatch $match): bool => \in_array($match->getEsiJobId(), $esiJobIds, true),
            )),
        );

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => (new IndustryActivityProduct())
                ->setTypeId($productTypeId === self::ISHTAR_TYPE_ID ? self::ISHTAR_BLUEPRINT_TYPE_ID : self::RAM_BLUEPRINT_TYPE_ID)
                ->setActivityId(IndustryActivityType::Manufacturing->value)
                ->setProductTypeId($productTypeId)
                ->setQuantity(1),
        );

        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => match ([$productTypeId, $activityId]) {
                [self::ISHTAR_TYPE_ID, IndustryActivityType::Manufacturing->value] => (new IndustryActivityProduct())
                    ->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
                    ->setActivityId(IndustryActivityType::Manufacturing->value)
                    ->setProductTypeId(self::ISHTAR_TYPE_ID)
                    ->setQuantity(1),
                [self::ISHTAR_BLUEPRINT_TYPE_ID, IndustryActivityType::Invention->value] => (new IndustryActivityProduct())
                    ->setTypeId(self::VEXOR_BLUEPRINT_TYPE_ID)
                    ->setActivityId(IndustryActivityType::Invention->value)
                    ->setProductTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
                    ->setQuantity(1),
                default => null,
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedEntities[] = $entity;
        });

        $matcher = new IndustryJobMatcher(
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $productRepository,
            $jobMatchRepository,
            $entityManager,
            new NullLogger(),
            $this->createStub(ProjectContainerService::class),
            $this->createStub(IndustryProjectLineReservationRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $unassignedJobRepository ?? $this->createStub(UnassignedJobRepository::class),
        );

        return new ManualJobLinker($matcher, $jobMatchRepository, $this->derivedContributionService());
    }
}
