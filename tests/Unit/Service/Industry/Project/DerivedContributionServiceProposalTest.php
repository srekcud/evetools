<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(DerivedContributionService::class)]
#[Group('issue-953')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceProposalTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;

    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectBomLine $line;
    private DerivedContributionService $service;

    protected function setUp(): void
    {
        $owner = new User();
        $this->project = (new IndustryProject())->setOwner($owner);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Admin)->setStatus(ParticipantStatus::Accepted),
        );
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(1000)->setIsJob(false);
        $this->project->addBomItem($this->line);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->project->addContribution($entity);
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->project->getContributions()->removeElement($entity);
            }
        });

        $this->service = new DerivedContributionService(
            $entityManager,
            new MercurePublisherService($this->createMock(HubInterface::class), new NullLogger()),
            $this->createMock(NotificationDispatcher::class),
        );
    }

    public function testLinkedPurchaseOfAGroupProjectMakesAPendingUnverifiedProposalAtThePaidPriceAndServesNothing(): void
    {
        $this->linkPurchase(100, 5.0);

        $this->service->refreshLine($this->line);

        $proposal = $this->derivedContribution();
        $this->assertSame(
            [ContributionType::Material, 100, 500.0, ContributionStatus::Pending, true, false],
            [$proposal->getType(), $proposal->getQuantity(), $proposal->getEstimatedValue(), $proposal->getStatus(), $proposal->isAutoDetected(), $proposal->isVerified()],
        );
        $this->assertSame($this->ownerParticipant, $proposal->getMember());
        $this->assertNull($proposal->getReviewedBy());
        $this->assertNull($proposal->getReviewedAt());
        $this->assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testShrinkingAnApprovedDerivedContributionMovesTheLineByTheDifference(): void
    {
        $purchase = $this->linkPurchase(100, 5.0);
        $this->service->refreshLine($this->line);
        $this->approve($this->derivedContribution());
        $this->line->getPurchases()->removeElement($purchase);
        $this->linkPurchase(60, 5.0);

        $this->service->refreshLine($this->line);

        $contribution = $this->derivedContribution();
        $this->assertSame([60, 300.0, ContributionStatus::Approved], [$contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus()]);
        $this->assertSame(60, $this->line->getFulfilledQuantity());
    }

    public function testChangingAPendingProposalKeepsItPendingAndLeavesTheLineAlone(): void
    {
        $this->linkPurchase(100, 5.0);
        $this->service->refreshLine($this->line);
        $this->linkPurchase(50, 6.0);

        $this->service->refreshLine($this->line);

        $proposal = $this->derivedContribution();
        $this->assertSame([150, 800.0, ContributionStatus::Pending], [$proposal->getQuantity(), $proposal->getEstimatedValue(), $proposal->getStatus()]);
        $this->assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testRemovingAPendingProposalLeavesTheLineAlone(): void
    {
        $purchase = $this->linkPurchase(100, 5.0);
        $this->line->setFulfilledQuantity(30);
        $this->service->refreshLine($this->line);
        $this->line->getPurchases()->removeElement($purchase);

        $this->service->refreshLine($this->line);

        $this->assertSame([], $this->project->getContributions()->toArray());
        $this->assertSame(30, $this->line->getFulfilledQuantity());
    }

    public function testRemovingAnApprovedDerivedContributionTakesItsQuantityOffTheLine(): void
    {
        $purchase = $this->linkPurchase(100, 5.0);
        $this->service->refreshLine($this->line);
        $this->approve($this->derivedContribution());
        $this->line->getPurchases()->removeElement($purchase);

        $this->service->refreshLine($this->line);

        $this->assertSame([], $this->project->getContributions()->toArray());
        $this->assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testARejectedProposalStaysRejectedWhenThePurchasesGrow(): void
    {
        $this->linkPurchase(100, 5.0);
        $this->service->refreshLine($this->line);
        $proposal = $this->derivedContribution();
        $proposal->setStatus(ContributionStatus::Rejected);
        $this->linkPurchase(100, 5.0);

        $this->service->refreshLine($this->line);

        $this->assertSame([ContributionStatus::Rejected, 200], [$proposal->getStatus(), $proposal->getQuantity()]);
        $this->assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testDerivedJobInstallOfAGroupProjectStaysApprovedAtOnce(): void
    {
        $job = (new IndustryProjectBomLine())->setTypeId(12005)->setTypeName('Ishtar')->setRequiredQuantity(5)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing')->setRuns(5);
        $this->project->addBomItem($job);
        $job->addJobMatch((new \App\Entity\IndustryProjectJobMatch())->setEsiJobId(1)->setRuns(5)->setCost(1000.0));

        $this->service->refreshLine($job);

        $install = $this->derivedContribution($job);
        $this->assertSame([ContributionType::JobInstall, ContributionStatus::Approved, true], [$install->getType(), $install->getStatus(), $install->isVerified()]);
    }

    public function testBoughtComponentOfAJobLineStaysApprovedAtOnceEvenInAGroupProject(): void
    {
        $component = (new IndustryProjectBomLine())->setTypeId(11399)->setTypeName('Morphite')->setRequiredQuantity(100)->setIsJob(true)->setJobGroup('component')->setActivityType('manufacturing')->setRuns(2);
        $this->project->addBomItem($component);
        $component->addPurchase((new IndustryProjectPurchase())->setTypeId(11399)->setQuantity(30)->setUnitPrice(10.0)->setTotalPrice(300.0)->setSource('manual'));

        $this->service->refreshLine($component);

        $derived = $this->derivedContribution($component);
        $this->assertSame([ContributionStatus::Approved, true, 30], [$derived->getStatus(), $derived->isVerified(), $component->getFulfilledQuantity()]);
    }

    private function linkPurchase(int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($unitPrice * $quantity)
            ->setSource('esi_wallet');
        $this->line->addPurchase($purchase);

        return $purchase;
    }

    private function approve(IndustryProjectContribution $contribution): void
    {
        $contribution->setStatus(ContributionStatus::Approved);
        $this->line->setFulfilledQuantity($this->line->getFulfilledQuantity() + $contribution->getQuantity());
    }

    private function derivedContribution(?IndustryProjectBomLine $line = null): IndustryProjectContribution
    {
        $line ??= $this->line;
        $derived = array_values(array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected() && $contribution->getBomItem() === $line,
        ));
        $this->assertCount(1, $derived);

        return $derived[0];
    }
}
