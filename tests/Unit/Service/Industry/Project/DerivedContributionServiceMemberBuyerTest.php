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
#[Group('issue-981')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceMemberBuyerTest extends TestCase
{
    private const int PYERITE_TYPE_ID = 35;
    private const int PYERITE_LINE_QUANTITY = 5_000;

    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $memberParticipant;
    private IndustryProjectBomLine $line;
    private DerivedContributionService $service;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->memberParticipant = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->memberParticipant);
        $this->line = (new IndustryProjectBomLine())
            ->setTypeId(self::PYERITE_TYPE_ID)
            ->setTypeName('Pyerite')
            ->setRequiredQuantity(self::PYERITE_LINE_QUANTITY)
            ->setIsJob(false);
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

    public function testPurchaseOfAMemberMakesAPendingUnverifiedProposalAtHisPaidPriceAndServesNothingWhenTheOwnerIsTheSoleReviewer(): void
    {
        $this->linkPurchase($this->memberParticipant, 100, 7.5);

        $this->service->refreshLine($this->line);

        $proposal = $this->derivedContributionOf($this->memberParticipant);
        self::assertSame(
            [ContributionType::Material, 100, 750.0, ContributionStatus::Pending, true, false],
            [$proposal->getType(), $proposal->getQuantity(), $proposal->getEstimatedValue(), $proposal->getStatus(), $proposal->isAutoDetected(), $proposal->isVerified()],
        );
        self::assertNull($proposal->getReviewedBy());
        self::assertNull($proposal->getReviewedAt());
        self::assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testPurchasesOfTheOwnerAndOfAMemberMakeTwoDistinctContributionsEachAtItsOwnPaidPrice(): void
    {
        $this->linkPurchase($this->ownerParticipant, 60, 6.0);
        $this->linkPurchase($this->memberParticipant, 40, 8.5);

        $this->service->refreshLine($this->line);

        $ownerContribution = $this->derivedContributionOf($this->ownerParticipant);
        $memberContribution = $this->derivedContributionOf($this->memberParticipant);
        self::assertSame(
            [[60, 360.0, ContributionStatus::Approved], [40, 340.0, ContributionStatus::Pending]],
            [
                [$ownerContribution->getQuantity(), $ownerContribution->getEstimatedValue(), $ownerContribution->getStatus()],
                [$memberContribution->getQuantity(), $memberContribution->getEstimatedValue(), $memberContribution->getStatus()],
            ],
        );
        self::assertSame(60, $this->line->getFulfilledQuantity());
    }

    public function testSeveralPurchasesOfTheSameMemberAreSummedIntoOneProposal(): void
    {
        $this->linkPurchase($this->memberParticipant, 30, 7.0);
        $this->linkPurchase($this->memberParticipant, 20, 9.0);

        $this->service->refreshLine($this->line);

        $proposal = $this->derivedContributionOf($this->memberParticipant);
        self::assertSame([50, 390.0], [$proposal->getQuantity(), $proposal->getEstimatedValue()]);
        self::assertCount(1, $this->derivedContributions());
    }

    public function testRemovingThePurchaseOfTheMemberRemovesOnlyHisContribution(): void
    {
        $this->linkPurchase($this->ownerParticipant, 60, 6.0);
        $memberPurchase = $this->linkPurchase($this->memberParticipant, 40, 8.5);
        $this->service->refreshLine($this->line);
        self::assertSame(40, $this->derivedContributionOf($this->memberParticipant)->getQuantity());
        $this->line->getPurchases()->removeElement($memberPurchase);

        $this->service->refreshLine($this->line);

        $remaining = $this->derivedContributions();
        self::assertCount(1, $remaining);
        self::assertSame([$this->ownerParticipant, 60, 360.0], [$remaining[0]->getMember(), $remaining[0]->getQuantity(), $remaining[0]->getEstimatedValue()]);
        self::assertSame(60, $this->line->getFulfilledQuantity());
    }

    public function testRemovingTheApprovedProposalOfAMemberTakesItsQuantityOffTheLine(): void
    {
        $memberPurchase = $this->linkPurchase($this->memberParticipant, 40, 8.5);
        $this->service->refreshLine($this->line);
        $this->derivedContributionOf($this->memberParticipant)->setStatus(ContributionStatus::Approved);
        $this->line->setFulfilledQuantity(40);
        $this->line->getPurchases()->removeElement($memberPurchase);

        $this->service->refreshLine($this->line);

        self::assertSame([], $this->derivedContributions());
        self::assertSame(0, $this->line->getFulfilledQuantity());
    }

    public function testPurchaseWithoutBuyerStaysTheContributionOfTheOwnerApprovedAtOnceAsBefore(): void
    {
        $this->linkPurchase(null, 100, 7.5);

        $this->service->refreshLine($this->line);

        $contribution = $this->derivedContributionOf($this->ownerParticipant);
        self::assertSame(
            [100, 750.0, ContributionStatus::Approved, true],
            [$contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus(), $contribution->isVerified()],
        );
        self::assertCount(1, $this->derivedContributions());
        self::assertSame(100, $this->line->getFulfilledQuantity());
    }

    private function linkPurchase(?IndustryProjectParticipant $buyer, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setBuyer($buyer)
            ->setTypeId(self::PYERITE_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($unitPrice * $quantity)
            ->setSource('esi_wallet');
        $this->line->addPurchase($purchase);

        return $purchase;
    }

    private function derivedContributions(): array
    {
        return array_values(array_filter(
            $this->project->getContributions()->toArray(),
            fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected() && $contribution->getBomItem() === $this->line,
        ));
    }

    private function derivedContributionOf(IndustryProjectParticipant $participant): IndustryProjectContribution
    {
        $contributions = array_values(array_filter(
            $this->derivedContributions(),
            static fn (IndustryProjectContribution $contribution): bool => $contribution->getMember() === $participant,
        ));
        self::assertCount(1, $contributions);

        return $contributions[0];
    }
}
