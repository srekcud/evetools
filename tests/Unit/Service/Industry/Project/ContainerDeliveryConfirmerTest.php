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
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ContainerDeliveryConfirmer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(ContainerDeliveryConfirmer::class)]
#[Group('issue-953')]
#[AllowMockObjectsWithoutExpectations]
final class ContainerDeliveryConfirmerTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int CORPORATION_ID = 98_000_123;
    private const string FIRST_PROPOSAL_DATE = '2026-11-10 10:00:00 +00:00';

    private IndustryProject $project;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $pyeriteLine;

    private ProjectContainerStock $containerStock;

    /** @var list<IndustryProjectContribution> */
    private array $confirmedProposals = [];

    /** @var list<array<string, mixed>> */
    private array $publishedEvents = [];

    private int $flushCount = 0;

    private ProjectContainerService $containerService;
    private ProjectContributionService $contributionService;
    private IndustryProjectRepository $projectRepository;
    private EntityManagerInterface $entityManager;
    private ContainerDeliveryConfirmer $confirmer;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->project->addMember(
            (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Admin)->setStatus(ParticipantStatus::Accepted),
        );
        $this->tritaniumLine = $this->materialLine(self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000);
        $this->pyeriteLine = $this->materialLine(self::PYERITE_TYPE_ID, 'Pyerite', 3_000);
        $this->containerStock = new ProjectContainerStock(null);

        $this->containerService = $this->createStub(ProjectContainerService::class);
        $this->containerService->method('containerStockOf')->willReturnCallback(fn (): ProjectContainerStock => $this->containerStock);

        $this->contributionService = $this->createMock(ProjectContributionService::class);
        $this->contributionService->method('confirmDelivery')->willReturnCallback(function (IndustryProjectContribution $proposal): void {
            $this->confirmedProposals[] = $proposal;
            $proposal->setStatus(ContributionStatus::Approved);
            $proposal->getBomItem()->setFulfilledQuantity($proposal->getBomItem()->getFulfilledQuantity() + $proposal->getQuantity());
        });

        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedEvents[] = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR) + ['topic' => $update->getTopics()[0]];

            return 'published';
        });

        $this->confirmer = new ContainerDeliveryConfirmer(
            $this->containerService,
            $this->contributionService,
            $this->projectRepository,
            $this->entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            new NullLogger(),
        );
    }

    public function testProposalCoveredByTheContainerStockIsConfirmed(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $proposal = $this->proposal($this->tritaniumLine, 100);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(1, $confirmedCount);
        $this->assertSame($this->idsOf([$proposal]), $this->idsOf($this->confirmedProposals));
        $this->assertSame(100, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testProposalAboveTheContainerStockStaysPendingAndNothingIsFlushed(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 60]);
        $this->proposal($this->tritaniumLine, 100);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
        $this->assertSame(0, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame(0, $this->flushCount);
        $this->assertSame([], $this->publishedEvents);
    }

    public function testTypeAbsentFromTheContainerStockConfirmsNothing(): void
    {
        $this->stockOf([self::PYERITE_TYPE_ID => 500]);
        $this->proposal($this->tritaniumLine, 100);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testOldestProposalIsConfirmedFirstAndTheNextOneIsKeptWhenTheCumulatedQuantityExceedsTheStock(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $newest = $this->proposal($this->tritaniumLine, 50, 1);
        $oldest = $this->proposal($this->tritaniumLine, 60, 0);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(1, $confirmedCount);
        $this->assertSame($this->idsOf([$oldest]), $this->idsOf($this->confirmedProposals));
        $this->assertSame(60, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame(ContributionStatus::Pending, $newest->getStatus());
    }

    public function testProposalsFittingTheStockTogetherAreConfirmedFromTheOldestToTheNewest(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 110]);
        $newest = $this->proposal($this->tritaniumLine, 50, 1);
        $oldest = $this->proposal($this->tritaniumLine, 60, 0);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(2, $confirmedCount);
        $this->assertSame($this->idsOf([$oldest, $newest]), $this->idsOf($this->confirmedProposals));
        $this->assertSame(110, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testTheLineIsStoppedAtTheFirstProposalThatDoesNotFitEvenWhenALaterOneWould(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 50]);
        $this->proposal($this->tritaniumLine, 80, 0);
        $this->proposal($this->tritaniumLine, 10, 1);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testAStoppedLineDoesNotStopTheOtherLines(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 50, self::PYERITE_TYPE_ID => 30]);
        $this->proposal($this->tritaniumLine, 80, 0);
        $pyeriteProposal = $this->proposal($this->pyeriteLine, 20, 1);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(1, $confirmedCount);
        $this->assertSame($this->idsOf([$pyeriteProposal]), $this->idsOf($this->confirmedProposals));
    }

    public function testTheCumulativeQuantityStartsAtTheQuantityAlreadyServedByTheLine(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 70]);
        $this->tritaniumLine->setFulfilledQuantity(40);
        $proposal = $this->proposal($this->tritaniumLine, 30);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(1, $confirmedCount);
        $this->assertSame($this->idsOf([$proposal]), $this->idsOf($this->confirmedProposals));
        $this->assertSame(70, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testProposalAboveTheStockLeftOnceTheServedQuantityIsCountedStaysPending(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 70]);
        $this->tritaniumLine->setFulfilledQuantity(40);
        $this->proposal($this->tritaniumLine, 31);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame(40, $this->tritaniumLine->getFulfilledQuantity());
    }

    #[DataProvider('unknownStockReasons')]
    public function testUnknownContainerStockConfirmsNothingAndRaisesNothing(string $unknownReason): void
    {
        $this->containerStock = new ProjectContainerStock($unknownReason, [self::TRITANIUM_TYPE_ID => 5_000]);
        $proposal = $this->proposal($this->tritaniumLine, 100);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
        $this->assertSame(ContributionStatus::Pending, $proposal->getStatus());
        $this->assertSame(0, $this->flushCount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownStockReasons(): iterable
    {
        yield 'corporation assets not synced' => ['corporation_assets_not_synced'];
        yield 'container not found' => ['container_not_found'];
    }

    public function testManuallyDeclaredPendingContributionIsNeverConfirmed(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100)->setIsAutoDetected(false);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testProposalAlreadyVerifiedIsNotConfirmedAgain(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100)->setVerifiedAt(new \DateTimeImmutable('2026-11-11 08:00:00 +00:00'));

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testAutoDetectedContributionOtherThanAMaterialIsNeverConfirmed(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100)->setType(ContributionType::JobInstall);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testAlreadyApprovedContributionIsNeverConfirmedAgain(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100)->setStatus(ContributionStatus::Approved);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    public function testProposalOnAJobLineIsNeverConfirmed(): void
    {
        $this->stockOf([self::MEXALLON_TYPE_ID => 100]);
        $jobLine = (new IndustryProjectBomLine())->setTypeId(self::MEXALLON_TYPE_ID)->setTypeName('Mexallon')->setRequiredQuantity(100)->setIsJob(true)->setJobGroup('final')->setActivityType('manufacturing')->setRuns(100);
        $this->project->addBomItem($jobLine);
        $this->proposal($jobLine, 100);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
    }

    #[DataProvider('closedProjectStatuses')]
    public function testProposalOfASellingOrCompletedProjectIsNeverConfirmed(ProjectStatus $status): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100);
        $this->project->setStatus($status);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $this->assertSame(0, $confirmedCount);
        $this->assertSame([], $this->idsOf($this->confirmedProposals));
        $this->assertSame(0, $this->flushCount);
    }

    /**
     * @return iterable<string, array{ProjectStatus}>
     */
    public static function closedProjectStatuses(): iterable
    {
        yield 'selling' => [ProjectStatus::Selling];
        yield 'completed' => [ProjectStatus::Completed];
    }

    public function testEachConfirmedProposalPublishesAContributionSyncedEventAndTheProjectIsFlushedOnce(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 20]);
        $tritaniumProposal = $this->proposal($this->tritaniumLine, 100, 0);
        $pyeriteProposal = $this->proposal($this->pyeriteLine, 20, 1);

        $confirmedCount = $this->confirmer->confirmProject($this->project);

        $projectId = $this->project->getId()->toRfc4122();
        $this->assertSame(2, $confirmedCount);
        $this->assertSame(1, $this->flushCount);
        $this->assertSame(
            [
                ['contribution_synced', $projectId, '/group-project/'.$projectId.'/events', $tritaniumProposal->getId()->toRfc4122(), $this->tritaniumLine->getId()->toRfc4122()],
                ['contribution_synced', $projectId, '/group-project/'.$projectId.'/events', $pyeriteProposal->getId()->toRfc4122(), $this->pyeriteLine->getId()->toRfc4122()],
            ],
            array_map(
                static fn (array $event): array => [$event['action'], $event['projectId'], $event['topic'], $event['data']['contributionId'], $event['data']['bomItemId']],
                $this->publishedEvents,
            ),
        );
    }

    public function testConfirmingOpenProjectsOfACorporationSumsTheConfirmedProposalsOfEveryProject(): void
    {
        $this->stockOf([self::TRITANIUM_TYPE_ID => 100]);
        $this->proposal($this->tritaniumLine, 100);
        $secondProject = (new IndustryProject())->setOwner(new User())->setStatus(ProjectStatus::InProgress);
        $secondProject->enrolOwner();
        $secondLine = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(900)->setIsJob(false);
        $secondProject->addBomItem($secondLine);
        $secondProposal = $this->proposalOf($secondProject, $secondLine, 70);
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->once())->method('findOpenOwnedByCorporation')->with(self::CORPORATION_ID)->willReturn([$this->project, $secondProject]);
        $confirmer = new ContainerDeliveryConfirmer(
            $this->containerService,
            $this->contributionService,
            $projectRepository,
            $this->entityManager,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            new NullLogger(),
        );

        $confirmedCount = $confirmer->confirmOpenProjectsOfCorporation(self::CORPORATION_ID);

        $this->assertSame(2, $confirmedCount);
        $this->assertContains($secondProposal->getId()->toRfc4122(), $this->idsOf($this->confirmedProposals));
        $this->assertSame(2, $this->flushCount);
    }

    public function testNoOpenProjectInTheCorporationConfirmsNothing(): void
    {
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->projectRepository->method('findOpenOwnedByCorporation')->willReturn([]);
        $confirmer = new ContainerDeliveryConfirmer(
            $this->containerService,
            $this->contributionService,
            $this->projectRepository,
            $this->entityManager,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            new NullLogger(),
        );

        $this->assertSame(0, $confirmer->confirmOpenProjectsOfCorporation(self::CORPORATION_ID));
        $this->assertSame(0, $this->flushCount);
    }

    /**
     * @param list<IndustryProjectContribution> $proposals
     *
     * @return list<string>
     */
    private function idsOf(array $proposals): array
    {
        return array_map(static fn (IndustryProjectContribution $proposal): string => $proposal->getId()->toRfc4122(), $proposals);
    }

    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    private function stockOf(array $quantitiesByTypeId): void
    {
        $this->containerStock = new ProjectContainerStock(null, $quantitiesByTypeId);
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        $this->project->addBomItem($line);

        return $line;
    }

    private function proposal(IndustryProjectBomLine $line, int $quantity, int $minutesAfterTheFirstProposal = 0): IndustryProjectContribution
    {
        return $this->proposalOf($this->project, $line, $quantity, $minutesAfterTheFirstProposal);
    }

    private function proposalOf(IndustryProject $project, IndustryProjectBomLine $line, int $quantity, int $minutesAfterTheFirstProposal = 0): IndustryProjectContribution
    {
        $proposal = (new IndustryProjectContribution())
            ->setProject($project)
            ->setMember($project->getMembers()->first())
            ->setBomItem($line)
            ->setType(ContributionType::Material)
            ->setQuantity($quantity)
            ->setEstimatedValue($quantity * 5.0)
            ->setStatus(ContributionStatus::Pending)
            ->setIsAutoDetected(true);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'createdAt'))->setValue(
            $proposal,
            (new \DateTimeImmutable(self::FIRST_PROPOSAL_DATE))->modify(sprintf('+%d minutes', $minutesAfterTheFirstProposal)),
        );
        $project->addContribution($proposal);

        return $proposal;
    }
}
