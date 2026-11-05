<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-362')]
final class IndustryProjectRouteSeparationTest extends IntegrationTestCase
{
    private const int OWNER_CORPORATION_ID = 98_362_001;

    private IndustryProjectRepository $repository;
    private User $owner;
    private IndustryProject $projectPlannedBySteps;
    private IndustryProject $projectWithItems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);

        $this->owner = $this->createUser();
        $mainCharacter = $this->createCharacter($this->owner, 'Route-Separation-Main')->setCorporationId(self::OWNER_CORPORATION_ID);
        $this->owner->setMainCharacter($mainCharacter);

        $this->projectPlannedBySteps = (new IndustryProject())
            ->setOwner($this->owner)
            ->setName('Rifter by steps')
            ->setStatus(GroupProjectStatus::Draft)
            ->setProductTypeId(587)
            ->setRuns(12)
            ->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(588)
                    ->setProductTypeId(587)
                    ->setQuantity(12)
                    ->setRuns(12)
                    ->setDepth(0),
            );
        $this->projectWithItems = (new IndustryProject())
            ->setOwner($this->owner)
            ->setName('Sabre by items')
            ->setStatus(GroupProjectStatus::Published)
            ->setProductTypeId(22456)
            ->setRuns(5)
            ->addItem(
                (new IndustryProjectProduct())
                    ->setTypeId(22456)
                    ->setTypeName('Sabre')
                    ->setMeLevel(10)
                    ->setTeLevel(20)
                    ->setRuns(5),
            );
        $this->em->persist($this->projectPlannedBySteps);
        $this->em->persist($this->projectWithItems);
        $this->flushAndClear();
    }

    public function testOwnerProjectsOfTheSoloRouteDoNotIncludeAProjectWithItems(): void
    {
        $projects = $this->repository->findByOwnerWithStepsAndJobMatches($this->em->getReference(User::class, $this->owner->getId()));

        self::assertSame(
            [(string) $this->projectPlannedBySteps->getId()],
            array_map(static fn (IndustryProject $project): string => (string) $project->getId(), $projects),
        );
    }

    public function testShortLinkCodeAndOwnerCorporationListOfTheGroupRouteDoNotIncludeAProjectPlannedBySteps(): void
    {
        $codeOfTheProjectWithItems = $this->projectWithItems->getShortLinkCode();
        $codeOfTheProjectPlannedBySteps = $this->projectPlannedBySteps->getShortLinkCode();

        $projectsOfTheCorporation = $this->repository->findByOwnerCorporation(
            self::OWNER_CORPORATION_ID,
            [GroupProjectStatus::Draft, GroupProjectStatus::Published],
        );

        self::assertSame((string) $this->projectWithItems->getId(), (string) $this->repository->findByShortLinkCode($codeOfTheProjectWithItems)?->getId());
        self::assertNull($this->repository->findByShortLinkCode($codeOfTheProjectPlannedBySteps));
        self::assertSame(
            [(string) $this->projectWithItems->getId()],
            array_map(static fn (IndustryProject $project): string => (string) $project->getId(), $projectsOfTheCorporation),
        );
    }
}
