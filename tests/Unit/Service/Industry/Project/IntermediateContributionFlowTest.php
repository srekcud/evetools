<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-706')]
final class IntermediateContributionFlowTest extends TestCase
{
    use BuildsProjectServiceOnGoldenSde;

    private const float HYDROGEN_FUEL_BLOCK_JITA_PRICE = 2_150.0;
    private const float FERNITE_ALLOY_JITA_PRICE = 3_800.5;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const int LINKED_ESI_JOB_ID = 6_706_000_001;
    private const int PROJECT_MATERIAL_EFFICIENCY = 10;
    private const int PROJECT_TIME_EFFICIENCY = 20;

    private ProjectContributionService $contributionService;

    protected function setUp(): void
    {
        $this->setUpGoldenSde();
        $this->jitaPrices = [self::HYDROGEN_FUEL_BLOCK => self::HYDROGEN_FUEL_BLOCK_JITA_PRICE, self::FERNITE_ALLOY => self::FERNITE_ALLOY_JITA_PRICE];
        $this->contributionService = new ProjectContributionService(
            $this->contributionRepository,
            $this->entityManager,
            $this->jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->projectService,
        );
    }

    public function testSoloIntermediateContributionIsApprovedAtOnceAndReducesTheRunsOfItsLine(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');

        $contribution = $this->contributionService->submit($this->ownerOf($project), $hydrogenFuelBlock, ContributionType::Material, 30);

        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
        self::assertSame(
            [2, 110, 30, 80],
            [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity(), $hydrogenFuelBlock->getRemainingQuantity()],
        );
        self::assertSame(2, $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'copy', 'blueprint')->getRuns());
        self::assertSame(810, $this->materialLineOf($project, self::HYDROGEN_ISOTOPES)->getRequiredQuantity());
    }

    public function testPendingIntermediateContributionApprovedLaterReducesTheRunsOfItsLine(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $pending = (new IndustryProjectContribution())
            ->setProject($project)
            ->setMember($this->ownerOf($project))
            ->setBomItem($hydrogenFuelBlock)
            ->setType(ContributionType::Material)
            ->setQuantity(30)
            ->setEstimatedValue(0.0)
            ->setStatus(ContributionStatus::Pending);
        $project->addContribution($pending);
        $this->contributions[] = $pending;

        $this->contributionService->approve($pending, $this->owner);

        self::assertSame([2, 110, 30], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testRejectingAnApprovedIntermediateContributionThatDuplicatesADerivedOneGivesBackTheRunsOfItsLine(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $derived = $this->approvedMaterialContribution($project, $hydrogenFuelBlock, 40);
        $derived->setIsAutoDetected(true);
        $declared = $this->approvedMaterialContribution($project, $hydrogenFuelBlock, 30);
        $this->projectService->buildBom($project, $this->owner);
        self::assertSame([1, 110, 70], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);

        $this->contributionService->reject($declared, $this->owner);

        self::assertSame([2, 120, 40], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testIntermediateContributionValuedAtTheJitaPriceOfTheDepositDayTimesItsQuantity(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();

        $contribution = $this->contributionService->submit(
            $this->ownerOf($project),
            $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component'),
            ContributionType::Material,
            30,
        );

        self::assertSame(64_500.0, $contribution->getEstimatedValue());
        self::assertContains([[self::HYDROGEN_FUEL_BLOCK], 0.05], $this->jitaLookups);
    }

    public function testIntermediateContributionThatWouldCutTheRunsBelowTheRunsOfALinkedJobIsRefusedAndNothingIsCommitted(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $hydrogenFuelBlock->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(self::LINKED_ESI_JOB_ID)->setRuns(3));
        $this->snapshotAsCommitted($project);
        $committedBefore = $this->committedState;

        $failure = $this->failureOf(fn () => $this->contributionService->submit($this->ownerOf($project), $hydrogenFuelBlock, ContributionType::Material, 30));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertSame($committedBefore, $this->committedState);
        self::assertSame([], $committedBefore['approvedContributions']);
    }

    public function testIntermediateContributionThatWouldCutALeafBelowWhatWasAlreadyContributedToItIsRefusedAndNothingIsCommitted(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenIsotopes = $this->materialLineOf($project, self::HYDROGEN_ISOTOPES);
        self::assertSame(1_215, $hydrogenIsotopes->getRequiredQuantity());
        $this->approvedMaterialContribution($project, $hydrogenIsotopes, 1_000);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $this->snapshotAsCommitted($project);
        $committedBefore = $this->committedState;

        $failure = $this->failureOf(fn () => $this->contributionService->submit($this->ownerOf($project), $hydrogenFuelBlock, ContributionType::Material, 30));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertSame($committedBefore, $this->committedState);
        self::assertSame(1_000, $hydrogenIsotopes->getFulfilledQuantity());
    }

    public function testIntermediateContributionOnAJobLineOfAGroupProjectIsRefusedWhoeverMakesIt(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $member = $this->acceptedMemberOf($project);
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');

        $failure = $this->failureOf(fn () => $this->contributionService->submit($member, $hydrogenFuelBlock, ContributionType::Material, 30));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertMatchesRegularExpression('/intermediate contribution.*solo project/i', $failure->getMessage());
        self::assertSame([[3, 120, 0]], [[$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity(), $hydrogenFuelBlock->getFulfilledQuantity()]]);
        self::assertSame([], $this->contributions);
    }

    public function testSoloProjectBecomingAGroupKeepsItsApprovedIntermediateStockAndRefusesANewIntermediateContribution(): void
    {
        $project = $this->projectOfTenRunsOfFerniteCarbide();
        $hydrogenFuelBlock = $this->lineOf($project, self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 'component');
        $this->contributionService->submit($this->ownerOf($project), $hydrogenFuelBlock, ContributionType::Material, 30);
        self::assertSame([2, 110], [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getRequiredQuantity()]);
        $jobsBeforeTheInvitation = $this->jobs($project);
        $materialsBeforeTheInvitation = $this->materials($project);
        $this->acceptedMemberOf($project);

        $this->projectService->buildBom($project, $this->owner);

        self::assertSame($jobsBeforeTheInvitation, $this->jobs($project));
        self::assertSame($materialsBeforeTheInvitation, $this->materials($project));
        $failure = $this->failureOf(fn () => $this->contributionService->submit(
            $this->ownerOf($project),
            $this->lineOf($project, self::FERNITE_ALLOY, 'reaction', 'component'),
            ContributionType::Material,
            100,
        ));
        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertMatchesRegularExpression('/intermediate contribution.*solo project/i', $failure->getMessage());
        self::assertCount(1, $this->contributions);
    }

    private function projectOfTenRunsOfFerniteCarbide(): IndustryProject
    {
        $project = $this->soloProjectOf([[self::FERNITE_CARBIDE, 10, self::PROJECT_MATERIAL_EFFICIENCY, self::PROJECT_TIME_EFFICIENCY]]);
        $this->projectService->buildBom($project, $this->owner);

        return $project;
    }

    private function acceptedMemberOf(IndustryProject $project): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser($this->createStub(User::class))
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function failureOf(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
