<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectCockpitResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectSale;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectPriceAlert;
use App\Service\Industry\Project\ProjectPriceAlertReader;
use App\Service\Industry\SellOrders\ProjectSellOrder;
use App\Service\Industry\SellOrders\ProjectSellOrdersReader;
use App\Enum\OrderPositionState;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectCockpitInputFactory;
use App\State\Provider\Industry\Project\ProjectCockpitProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectCockpitProvider::class)]
#[Group('issue-336')]
final class ProjectCockpitProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int ISOGEN_TYPE_ID = 37;
    private const int SABRE_TYPE_ID = 22456;
    private const int SABRE_BLUEPRINT_TYPE_ID = 22457;
    private const int SABRE_RUNS = 5;

    private Security&Stub $security;
    private IndustryProjectBomLine $sabreJobLine;
    private ProjectPriceAlertReader&Stub $priceAlertReader;
    private ProjectSellOrdersReader&Stub $sellOrdersReader;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->priceAlertReader = $this->createStub(ProjectPriceAlertReader::class);
        $this->sellOrdersReader = $this->createStub(ProjectSellOrdersReader::class);
    }

    public function testCockpitWithoutConnectedUserIsUnauthorized(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->readCockpitOf($this->sabreBatch->getId()));
    }

    public function testCockpitOfAnUnknownProjectIsNotFound(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readCockpitOf(Uuid::v4()));
    }

    public function testCockpitIsRefusedToAStrangerAndToACorpmateOfTheOwnerWhoIsNotAParticipant(): void
    {
        $this->createSabreBatch();

        $this->security->method('getUser')->willReturnOnConsecutiveCalls(
            $this->userWithProfile(self::STRANGER),
            $this->userWithProfile(self::OWNER_CORPORATION_NON_MEMBER),
        );

        self::assertSame(
            [self::NO_ACCESS, self::NO_ACCESS],
            [$this->readCockpitOf($this->sabreBatch->getId()), $this->readCockpitOf($this->sabreBatch->getId())],
        );
    }

    public function testAcceptedMemberReadsThePhasesAndTheNextActionComputedFromTheProjectBomSalesAndMatchedRuns(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $member = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $this->security->method('getUser')->willReturn($member);

        $cockpit = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertInstanceOf(ProjectCockpitResource::class, $cockpit);
        self::assertSame([
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'status' => 'in_progress',
            'phases' => [
                ['key' => 'prepare', 'state' => 'done', 'counter' => ['done' => 1, 'total' => 1], 'blockedReason' => null],
                ['key' => 'supply', 'state' => 'done', 'counter' => ['done' => 2, 'total' => 2], 'blockedReason' => null],
                ['key' => 'produce', 'state' => 'current', 'counter' => ['done' => 1, 'total' => 2], 'blockedReason' => null],
                ['key' => 'sell', 'state' => 'upcoming', 'counter' => ['done' => 1, 'total' => self::SABRE_RUNS], 'blockedReason' => null],
                ['key' => 'close', 'state' => 'upcoming', 'counter' => ['done' => 0, 'total' => 1], 'blockedReason' => null],
            ],
            'nextAction' => [
                'code' => 'launch_job',
                'blocked' => false,
                'tab' => 'materials',
                'targetStatus' => null,
                'bomLineId' => $this->sabreJobLine->getId()->toRfc4122(),
                'typeId' => self::SABRE_TYPE_ID,
                'typeName' => 'Sabre',
                'quantity' => 3,
            ],
            'priceAlert' => null,
            'undercutOrders' => [],
        ], get_object_vars($cockpit));
    }

    public function testCockpitCarriesThePriceAlertOfTheProjectWhenJitaIsBelowTheBreakEven(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));
        $this->priceAlertReader->method('of')->with($this->sabreBatch)->willReturn(new ProjectPriceAlert(self::SABRE_TYPE_ID, 'Sabre', 4, 690_000.0, 760_000.0));

        $cockpit = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame(
            ['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'quantity' => 4, 'jitaUnitPrice' => 690_000.0, 'breakEvenPerUnit' => 760_000.0, 'gapPerUnit' => 70_000.0],
            $cockpit->priceAlert,
        );
    }

    public function testCockpitCarriesTheUndercutSellOrdersOfTheViewer(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $viewer = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $this->security->method('getUser')->willReturn($viewer);
        $order = new ProjectSellOrder(11, 'Aria Solberg', self::SABRE_TYPE_ID, 'Sabre', 4, 60_003_760, 'Jita IV - Moon 4', 760_000.0, 690_000.0, 3, OrderPositionState::Undercut, null, new \DateTimeImmutable('2026-11-10 12:10:00'), 689_900.0);
        $this->sellOrdersReader->method('undercutOrdersOf')->with($this->sabreBatch, $viewer)->willReturn([$order]);

        $cockpit = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame([$order->toArray()], $cockpit->undercutOrders);
        self::assertSame(
            [
                'orderId' => 11,
                'characterName' => 'Aria Solberg',
                'typeId' => self::SABRE_TYPE_ID,
                'typeName' => 'Sabre',
                'volumeRemain' => 4,
                'locationId' => 60_003_760,
                'marketName' => 'Jita IV - Moon 4',
                'price' => 760_000.0,
                'bestPrice' => 690_000.0,
                'rank' => 3,
                'state' => 'undercut',
                'unknownReason' => null,
                'undercutSince' => (new \DateTimeImmutable('2026-11-10 12:10:00'))->format(\DATE_ATOM),
                'suggestedPrice' => 689_900.0,
            ],
            $order->toArray(),
        );
    }

    public function testOwnerAndAcceptedAdminReviewThePendingContributionsWhileAnAcceptedMemberLaunchesTheJob(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $admin = $this->userWithProfile(self::ACCEPTED_ADMIN);
        $member = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $this->security->method('getUser')->willReturnOnConsecutiveCalls($this->sabreBatchOwner, $admin, $member);

        $nextActions = array_map(
            fn (): array => $this->nextActionCodeAndQuantity(),
            ['owner', 'admin', 'member'],
        );

        self::assertSame([['review_contributions', 2], ['review_contributions', 2], ['launch_job', 3]], $nextActions);
    }

    private function nextActionCodeAndQuantity(): array
    {
        $cockpit = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);
        $nextAction = get_object_vars($cockpit)['nextAction'] ?? null;

        return [$nextAction['code'] ?? null, $nextAction['quantity'] ?? null];
    }

    private function stockSabreBatch(): void
    {
        $this->sabreBatch->addItem((new IndustryProjectProduct())->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')->setRuns(self::SABRE_RUNS));

        $this->sabreBatch->addBomItem($this->bomLine(self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 40_000, false, null, null));
        $this->sabreBatch->addBomItem($this->bomLine(self::ISOGEN_TYPE_ID, 'Isogen', 2_600, 2_650, false, null, null));
        $this->sabreBatch->addBomItem($this->bomLine(self::SABRE_BLUEPRINT_TYPE_ID, 'Sabre Blueprint', self::SABRE_RUNS, self::SABRE_RUNS, true, 'blueprint', self::SABRE_RUNS));
        $this->sabreJobLine = $this->bomLine(self::SABRE_TYPE_ID, 'Sabre', self::SABRE_RUNS, 0, true, 'final', self::SABRE_RUNS);
        $this->sabreJobLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(612_336_001)->setRuns(2)->setStatus('active'));
        $this->sabreBatch->addBomItem($this->sabreJobLine);

        $this->sabreBatch->addSale((new IndustryProjectSale())->setTypeId(self::SABRE_TYPE_ID)->setTypeName('Sabre')->setQuantity(1)->setUnitPrice(62_000_000.0));
        $this->sabreBatch->addSale((new IndustryProjectSale())->setTypeId(self::ISOGEN_TYPE_ID)->setTypeName('Isogen')->setQuantity(50)->setUnitPrice(60.0));

        $contributor = $this->addSabreBatchMembership($this->userOfCorporation(self::FOREIGN_CORPORATION_ID), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addSabreBatchContribution($contributor, 1_200, ContributionStatus::Pending);
        $this->addSabreBatchContribution($contributor, 800, ContributionStatus::Pending);
        $this->addSabreBatchContribution($contributor, 400, ContributionStatus::Approved);
        $this->addSabreBatchContribution($contributor, 300, ContributionStatus::Rejected);
    }

    private function bomLine(int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, bool $isJob, ?string $jobGroup, ?int $runs): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setIsJob($isJob)
            ->setJobGroup($jobGroup)
            ->setActivityType($isJob ? 'manufacturing' : null)
            ->setRuns($runs);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($line, Uuid::v4());

        return $line;
    }

    private function addSabreBatchContribution(IndustryProjectParticipant $contributor, int $quantity, ContributionStatus $status): void
    {
        $this->sabreBatch->addContribution(
            (new IndustryProjectContribution())
                ->setMember($contributor)
                ->setType(ContributionType::Material)
                ->setQuantity($quantity)
                ->setEstimatedValue($quantity * 7.5)
                ->setStatus($status),
        );
    }

    private function readCockpitOf(Uuid $projectId): string
    {
        return $this->outcomeOf(fn () => $this->provider()->provide(new Get(), ['projectId' => $projectId->toRfc4122()]));
    }

    private function provider(): ProjectCockpitProvider
    {
        return new ProjectCockpitProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            new ProjectCockpitCalculator(),
            new ProjectCockpitInputFactory(),
            $this->priceAlertReader,
            $this->sellOrdersReader,
        );
    }
}
