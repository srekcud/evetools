<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\Courier\CourierContractState;
use App\Service\Industry\Project\Courier\CourierLinkedContract;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(DerivedContributionService::class)]
#[Group('issue-421')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceCourierTest extends TestCase
{
    private const int CONTRACT_ID = 421_001;
    private const int CHARACTER_ID = 90_000_421;
    private const float COURIER_REWARD = 376_550.0;
    private const float RAISED_COURIER_REWARD = 400_000.0;
    private const float TRITANIUM_PURCHASE_TOTAL = 6_500.0;

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private DerivedContributionService $service;
    private User $owner;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectBomLine $tritaniumLine;
    private array $publishedUpdates = [];
    private array $persistedContributions = [];
    private array $removedContributions = [];
    private int $flushCount = 0;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->tritaniumLine = (new IndustryProjectBomLine())
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($this->tritaniumLine);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->persistedContributions[] = $entity;
                $this->project->addContribution($entity);
            }
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof IndustryProjectContribution) {
                $this->removedContributions[] = $entity;
                $this->project->getContributions()->removeElement($entity);
            }
        });
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });

        $this->service = new DerivedContributionService(
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testDeliveredContractCreatesTheOwnersApprovedDerivedCourierContributionWorthItsReward(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));

        $contribution = $this->singleCourierContribution();
        self::assertSame(
            [ContributionType::Courier, 1, self::COURIER_REWARD, ContributionStatus::Approved, true, true, null],
            [$contribution->getType(), $contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus(), $contribution->isAutoDetected(), $contribution->isVerified(), $contribution->getBomItem()],
        );
        self::assertSame($this->ownerParticipant, $contribution->getMember());
        self::assertSame($this->project, $contribution->getProject());
        self::assertSame($this->owner, $contribution->getReviewedBy());
        self::assertSame([$contribution], $this->persistedContributions);
        self::assertSame(1, $this->flushCount);
        self::assertCount(1, $this->publishedUpdates);
    }

    public function testDeliveredContractWithoutRewardCreatesTheContributionWithAnUnknownValueNeverZero(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, null));

        self::assertNull($this->singleCourierContribution()->getEstimatedValue());
    }

    public function testSyncingTheSameDeliveredContractAgainChangesNothingAndPublishesNothing(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));
        $contribution = $this->singleCourierContribution();
        $this->publishedUpdates = [];
        $this->flushCount = 0;

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));

        self::assertSame([$contribution], $this->courierContributions());
        self::assertCount(1, $this->persistedContributions);
        self::assertSame([], $this->removedContributions);
        self::assertSame(0, $this->flushCount);
        self::assertSame([], $this->publishedUpdates);
    }

    public function testRewardChangeUpdatesTheSameContributionInPlaceAndPublishesIt(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));
        $contribution = $this->singleCourierContribution();
        $this->publishedUpdates = [];

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::RAISED_COURIER_REWARD));

        self::assertSame([$contribution], $this->courierContributions());
        self::assertSame(self::RAISED_COURIER_REWARD, $contribution->getEstimatedValue());
        self::assertCount(1, $this->persistedContributions);
        self::assertCount(1, $this->publishedUpdates);
    }

    #[DataProvider('stateNotCounted')]
    public function testContractNotDeliveredCountsNothing(CourierContractState $state): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract($state, self::COURIER_REWARD));

        self::assertSame([], $this->courierContributions());
        self::assertSame([], $this->persistedContributions);
        self::assertSame(0, $this->flushCount);
        self::assertSame([], $this->publishedUpdates);
    }

    /**
     * @return iterable<string, array{CourierContractState}>
     */
    public static function stateNotCounted(): iterable
    {
        yield 'waiting' => [CourierContractState::Waiting];
        yield 'in transit' => [CourierContractState::InTransit];
        yield 'unknown' => [CourierContractState::Unknown];
        yield 'failed' => [CourierContractState::Failed];
        yield 'not shipped' => [CourierContractState::NotShipped];
    }

    #[DataProvider('stateRemovingTheContribution')]
    public function testContractNoLongerDeliveredPutsTheTransportBackToZero(?CourierContractState $state): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));
        $contribution = $this->singleCourierContribution();
        $this->publishedUpdates = [];

        $this->service->syncCourier($this->project, null === $state ? null : $this->linkedContract($state, self::COURIER_REWARD));

        self::assertSame([], $this->courierContributions());
        self::assertSame([$contribution], $this->removedContributions);
        self::assertCount(1, $this->publishedUpdates);
    }

    /**
     * @return iterable<string, array{CourierContractState|null}>
     */
    public static function stateRemovingTheContribution(): iterable
    {
        yield 'failed' => [CourierContractState::Failed];
        yield 'unlinked' => [null];
    }

    public function testUnreadableContractKeepsTheLastCountedRewardUntouched(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));
        $contribution = $this->singleCourierContribution();
        $this->publishedUpdates = [];
        $this->flushCount = 0;

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Unknown, null));

        self::assertSame([$contribution], $this->courierContributions());
        self::assertSame(self::COURIER_REWARD, $contribution->getEstimatedValue());
        self::assertSame([], $this->removedContributions);
        self::assertSame(0, $this->flushCount);
        self::assertSame([], $this->publishedUpdates);
    }

    public function testCompletedProjectKeepsItsCourierContributionWhateverTheContractBecomes(): void
    {
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));
        $contribution = $this->singleCourierContribution();
        $this->project->setStatus(ProjectStatus::Completed);

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Failed, self::COURIER_REWARD));
        $this->service->syncCourier($this->project, null);

        self::assertSame([$contribution], $this->courierContributions());
        self::assertSame([], $this->removedContributions);
    }

    public function testCompletedProjectDoesNotStartCountingADeliveredContract(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));

        self::assertSame([], $this->project->getContributions()->toArray());
    }

    public function testCourierSyncLeavesManualAndLineDerivedContributionsUntouched(): void
    {
        $manual = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerParticipant)
            ->setBomItem($this->tritaniumLine)
            ->setType(ContributionType::Material)
            ->setQuantity(250)
            ->setEstimatedValue(900.0)
            ->setStatus(ContributionStatus::Approved);
        $this->project->addContribution($manual);
        $lineDerived = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerParticipant)
            ->setBomItem($this->tritaniumLine)
            ->setType(ContributionType::JobInstall)
            ->setQuantity(3)
            ->setEstimatedValue(self::TRITANIUM_PURCHASE_TOTAL)
            ->setStatus(ContributionStatus::Approved)
            ->setIsAutoDetected(true);
        $this->project->addContribution($lineDerived);
        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));

        $this->service->syncCourier($this->project, null);

        self::assertSame([$manual, $lineDerived], $this->project->getContributions()->toArray());
        self::assertSame([900.0, self::TRITANIUM_PURCHASE_TOTAL], [$manual->getEstimatedValue(), $lineDerived->getEstimatedValue()]);
    }

    public function testNonOwnerParticipantNeverCarriesTheCourierContribution(): void
    {
        $successor = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($successor);

        $this->service->syncCourier($this->project, $this->linkedContract(CourierContractState::Delivered, self::COURIER_REWARD));

        self::assertSame($this->ownerParticipant, $this->singleCourierContribution()->getMember());
    }

    private function linkedContract(CourierContractState $state, ?float $reward): CourierLinkedContract
    {
        return new CourierLinkedContract(
            self::CONTRACT_ID,
            self::CHARACTER_ID,
            $state,
            null,
            25.0,
            $reward,
            376_550.0,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-11-07T10:00:00+00:00'),
        );
    }

    /**
     * @return list<IndustryProjectContribution>
     */
    private function courierContributions(): array
    {
        return array_values(array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => ContributionType::Courier === $contribution->getType(),
        ));
    }

    private function singleCourierContribution(): IndustryProjectContribution
    {
        $contributions = $this->courierContributions();
        self::assertCount(1, $contributions);

        return $contributions[0];
    }
}
