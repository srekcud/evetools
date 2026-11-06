<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(DerivedContributionService::class)]
#[Group('issue-363')]
#[AllowMockObjectsWithoutExpectations]
final class DerivedContributionServiceTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int TRITANIUM_LINE_QUANTITY = 1_000;
    private const int MANUALLY_DEPOSITED_QUANTITY = 250;

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private DerivedContributionService $service;
    private User $owner;
    private User $successor;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $successorParticipant;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $ishtarLine;
    private array $publishedUpdates = [];
    private array $persistedContributions = [];
    private array $removedContributions = [];

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->successor = new User();

        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->successorParticipant = (new IndustryProjectParticipant())
            ->setUser($this->successor)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->successorParticipant);

        $this->tritaniumLine = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(self::TRITANIUM_LINE_QUANTITY)
            ->setEstimatedPrice(5.5)
            ->setIsJob(false);
        $this->project->addBomItem($this->tritaniumLine);
        $this->ishtarLine = (new IndustryProjectBomLine())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setRequiredQuantity(5)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(5);
        $this->project->addBomItem($this->ishtarLine);

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

    public function testFirstLinkedPurchaseCreatesTheOwnersApprovedDerivedMaterialContributionAndServesTheLine(): void
    {
        $this->givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine();
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');

        $this->service->refreshLine($this->tritaniumLine);

        $derived = $this->derivedContributionsOf($this->tritaniumLine);
        $this->assertCount(1, $derived);
        $contribution = $derived[0];
        $this->assertSame(
            [ContributionType::Material, 400, 2600.0, ContributionStatus::Approved, true, true],
            [$contribution->getType(), $contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus(), $contribution->isAutoDetected(), $contribution->isVerified()],
        );
        $this->assertSame($this->ownerParticipant, $contribution->getMember());
        $this->assertSame($this->project, $contribution->getProject());
        $this->assertSame($this->tritaniumLine, $contribution->getBomItem());
        $this->assertSame($this->owner, $contribution->getReviewedBy());
        $this->assertNotNull($contribution->getReviewedAt());
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY + 400, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame([$contribution], $this->persistedContributions);
    }

    public function testSecondLinkedPurchaseUpdatesTheSameDerivedContributionInPlace(): void
    {
        $this->givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine();
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $this->service->refreshLine($this->tritaniumLine);
        $firstDerived = $this->singleDerivedContributionOf($this->tritaniumLine);

        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 600, 6.0, 'manual');
        $this->service->refreshLine($this->tritaniumLine);

        $derived = $this->derivedContributionsOf($this->tritaniumLine);
        $this->assertSame([$firstDerived], $derived);
        $this->assertSame([1000, 6200.0, ContributionStatus::Approved], [$firstDerived->getQuantity(), $firstDerived->getEstimatedValue(), $firstDerived->getStatus()]);
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY + 1000, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertCount(1, $this->persistedContributions);
        $this->assertSame([], $this->removedContributions);
    }

    public function testDeletingTheLinkedPurchasesShrinksThenRemovesTheDerivedContributionAndTheFulfilledQuantityFollows(): void
    {
        $this->givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine();
        $firstPurchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $secondPurchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 600, 6.0, 'manual');
        $this->service->refreshLine($this->tritaniumLine);
        $derived = $this->singleDerivedContributionOf($this->tritaniumLine);

        $this->tritaniumLine->getPurchases()->removeElement($secondPurchase);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([400, 2600.0], [$derived->getQuantity(), $derived->getEstimatedValue()]);
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY + 400, $this->tritaniumLine->getFulfilledQuantity());

        $this->tritaniumLine->getPurchases()->removeElement($firstPurchase);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([], $this->derivedContributionsOf($this->tritaniumLine));
        $this->assertSame([$derived], $this->removedContributions);
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testManualContributionsOfTheOwnerSurviveTheRemovalOfTheDerivedOne(): void
    {
        $manual = $this->givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine();
        $purchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $this->service->refreshLine($this->tritaniumLine);
        $derived = $this->singleDerivedContributionOf($this->tritaniumLine);

        $this->tritaniumLine->getPurchases()->removeElement($purchase);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([$manual], $this->project->getContributions()->toArray());
        $this->assertSame([ContributionStatus::Approved, false, 900.0], [$manual->getStatus(), $manual->isAutoDetected(), $manual->getEstimatedValue()]);
        $this->assertSame([$derived], $this->removedContributions);
    }

    public function testPurchaseOfAnotherTypeMakesNoDerivedContributionAndChangesNothing(): void
    {
        $this->linkPurchase($this->tritaniumLine, self::PYERITE_TYPE_ID, 700, 11.5, 'esi_wallet');

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame([], $this->project->getContributions()->toArray());
        $this->assertSame(0, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame([], $this->persistedContributions);
        $this->assertSame([], $this->publishedUpdates);
    }

    public function testRefreshingALineWhoseLinksDidNotChangePersistsAndPublishesNothingMore(): void
    {
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $this->service->refreshLine($this->tritaniumLine);

        $this->service->refreshLine($this->tritaniumLine);

        $this->assertCount(1, $this->persistedContributions);
        $this->assertCount(1, $this->publishedUpdates);
        $this->assertSame(400, $this->tritaniumLine->getFulfilledQuantity());
    }

    public function testTheFirstLinkTheChangeAndTheRemovalOfADerivedContributionEachPublishContributionSynced(): void
    {
        $purchase = $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $this->service->refreshLine($this->tritaniumLine);
        $purchase->setQuantity(450)->setTotalPrice(2925.0);
        $this->service->refreshLine($this->tritaniumLine);
        $this->tritaniumLine->getPurchases()->removeElement($purchase);
        $this->service->refreshLine($this->tritaniumLine);

        $this->assertSame(['contribution_synced', 'contribution_synced', 'contribution_synced'], array_column($this->publishedPayloads(), 'action'));
        $this->assertSame(
            array_fill(0, 3, $this->project->getId()->toRfc4122()),
            array_column($this->publishedPayloads(), 'projectId'),
        );
        $this->assertSame(
            ['/group-project/'.$this->project->getId()->toRfc4122().'/events'],
            $this->publishedUpdates[0]->getTopics(),
        );
    }

    public function testLinkedJobsMakeOneDerivedJobInstallContributionAtTheEsiCostWithoutServingTheLine(): void
    {
        $this->linkJob($this->ishtarLine, 6_363_000_001, 3, 214_000.0);
        $this->linkJob($this->ishtarLine, 6_363_000_002, 2, 150_000.0);

        $this->service->refreshLine($this->ishtarLine);

        $derived = $this->derivedContributionsOf($this->ishtarLine);
        $this->assertCount(1, $derived);
        $contribution = $derived[0];
        $this->assertSame(
            [ContributionType::JobInstall, 5, 364_000.0, ContributionStatus::Approved, true, true],
            [$contribution->getType(), $contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus(), $contribution->isAutoDetected(), $contribution->isVerified()],
        );
        $this->assertSame($this->ownerParticipant, $contribution->getMember());
        $this->assertSame($this->owner, $contribution->getReviewedBy());
        $this->assertSame(0, $this->ishtarLine->getFulfilledQuantity());
    }

    public function testLinkedJobWithoutACostMakesTheDerivedValueUnknownNotZero(): void
    {
        $this->linkJob($this->ishtarLine, 6_363_000_001, 3, 214_000.0);
        $this->linkJob($this->ishtarLine, 6_363_000_002, 2, null);

        $this->service->refreshLine($this->ishtarLine);

        $derived = $this->derivedContributionsOf($this->ishtarLine);
        $this->assertCount(1, $derived);
        $this->assertSame(5, $derived[0]->getQuantity());
        $this->assertNull($derived[0]->getEstimatedValue());
    }

    public function testCostCopiedByTheSyncOntoALinkChangesTheDerivedValueAndPublishesContributionSynced(): void
    {
        $firstJob = $this->linkJob($this->ishtarLine, 6_363_000_001, 3, 214_000.0);
        $this->linkJob($this->ishtarLine, 6_363_000_002, 2, 150_000.0);
        $this->service->refreshLine($this->ishtarLine);
        $publishedBefore = \count($this->publishedUpdates);

        $firstJob->setCost(220_000.0);
        $this->service->refreshLine($this->ishtarLine);

        $derived = $this->singleDerivedContributionOf($this->ishtarLine);
        $this->assertSame([5, 370_000.0], [$derived->getQuantity(), $derived->getEstimatedValue()]);
        $this->assertCount($publishedBefore + 1, $this->publishedUpdates);
        $payload = $this->publishedPayloads()[$publishedBefore];
        $this->assertSame('contribution_synced', $payload['action']);
        $this->assertSame($this->project->getId()->toRfc4122(), $payload['projectId']);
    }

    public function testJobLineWithABoughtComponentCarriesTheDerivedMaterialAndTheDerivedJobInstallSideBySide(): void
    {
        $this->linkPurchase($this->ishtarLine, self::ISHTAR_TYPE_ID, 2, 90_500_000.0, 'esi_wallet');
        $this->linkJob($this->ishtarLine, 6_363_000_003, 3, 214_000.0);

        $this->service->refreshLine($this->ishtarLine);

        $derived = $this->derivedContributionsOf($this->ishtarLine);
        $byType = [];
        foreach ($derived as $contribution) {
            $byType[$contribution->getType()->value] = [$contribution->getQuantity(), $contribution->getEstimatedValue()];
        }
        ksort($byType);
        $this->assertSame(['job_install' => [3, 214_000.0], 'material' => [2, 181_000_000.0]], $byType);
        $this->assertSame(2, $this->ishtarLine->getFulfilledQuantity());
    }

    public function testOwnershipTransferRemovesTheFormerOwnersDerivedContributionsAndCorrectsTheFulfilledQuantity(): void
    {
        $this->givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine();
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 400, 6.5, 'esi_wallet');
        $this->linkPurchase($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 600, 6.0, 'manual');
        $this->linkJob($this->ishtarLine, 6_363_000_001, 3, 214_000.0);
        $this->service->refreshLine($this->tritaniumLine);
        $this->service->refreshLine($this->ishtarLine);
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY + 1000, $this->tritaniumLine->getFulfilledQuantity());

        $this->project->transferOwnershipTo($this->successorParticipant);
        $this->service->refreshLine($this->tritaniumLine);
        $this->service->refreshLine($this->ishtarLine);

        $this->assertSame([], $this->derivedContributionsOf($this->tritaniumLine));
        $this->assertSame([], $this->derivedContributionsOf($this->ishtarLine));
        $this->assertCount(2, $this->removedContributions);
        $this->assertSame(self::MANUALLY_DEPOSITED_QUANTITY, $this->tritaniumLine->getFulfilledQuantity());
        $this->assertSame(0, $this->ishtarLine->getFulfilledQuantity());
        $this->assertCount(1, $this->project->getContributions());
    }

    private function givenAManualMaterialContributionOfTheOwnerServingTheTritaniumLine(): IndustryProjectContribution
    {
        $manual = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerParticipant)
            ->setBomItem($this->tritaniumLine)
            ->setType(ContributionType::Material)
            ->setQuantity(self::MANUALLY_DEPOSITED_QUANTITY)
            ->setEstimatedValue(900.0)
            ->setStatus(ContributionStatus::Approved)
            ->setIsAutoDetected(false)
            ->setIsVerified(false);
        $this->project->addContribution($manual);
        $this->tritaniumLine->setFulfilledQuantity(self::MANUALLY_DEPOSITED_QUANTITY);

        return $manual;
    }

    private function linkPurchase(IndustryProjectBomLine $line, int $typeId, int $quantity, float $unitPrice, string $source): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($unitPrice * $quantity)
            ->setSource($source);
        $line->addPurchase($purchase);

        return $purchase;
    }

    private function linkJob(IndustryProjectBomLine $line, int $esiJobId, int $runs, ?float $cost): IndustryProjectJobMatch
    {
        $jobMatch = (new IndustryProjectJobMatch())
            ->setEsiJobId($esiJobId)
            ->setRuns($runs)
            ->setCost($cost);
        $line->addJobMatch($jobMatch);

        return $jobMatch;
    }

    private function derivedContributionsOf(IndustryProjectBomLine $line): array
    {
        return array_values(array_filter(
            $this->project->getContributions()->toArray(),
            static fn (IndustryProjectContribution $contribution): bool => $contribution->isAutoDetected() && $contribution->getBomItem() === $line,
        ));
    }

    private function singleDerivedContributionOf(IndustryProjectBomLine $line): IndustryProjectContribution
    {
        $derived = $this->derivedContributionsOf($line);
        $this->assertCount(1, $derived);

        return $derived[0];
    }

    private function publishedPayloads(): array
    {
        return array_map(
            static fn (Update $update): array => json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR),
            $this->publishedUpdates,
        );
    }
}
