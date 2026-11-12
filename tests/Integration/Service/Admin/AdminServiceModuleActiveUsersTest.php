<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\MarketFavorite;
use App\Entity\MarketPriceAlert;
use App\Entity\MiningEntry;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\PlanetaryColony;
use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\PveIncomeType;
use App\Service\Admin\AdminService;
use App\Service\Admin\SyncTracker;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-650')]
final class AdminServiceModuleActiveUsersTest extends IntegrationTestCase
{
    private const array MODULES_IN_DISPLAY_ORDER = ['industry', 'groupIndustry', 'mining', 'planetary', 'market', 'pve', 'contracts'];

    private AdminService $adminService;

    /** @var array<string, array{0: ?int, 1: ?int}> */
    private array $before;

    protected function setUp(): void
    {
        parent::setUp();
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('getAll')->willReturn([]);
        $this->adminService = new AdminService($this->em->getConnection(), $syncTracker);
        $this->before = $this->countsByModule();
    }

    public function testTheSevenModulesAreAlwaysListedInDisplayOrderAndContractsIsUnknownNeverZero(): void
    {
        $rows = $this->adminService->getStats()['moduleActiveUsers'];

        self::assertSame(self::MODULES_IN_DISPLAY_ORDER, array_column($rows, 'module'));
        self::assertSame(
            ['module' => 'contracts', 'activeLast7Days' => null, 'activeLast30Days' => null],
            $rows[6],
        );
    }

    public function testAnOwnerIsCountedOncePerWindowAndOnlyWhenTheLoginIsInsideIt(): void
    {
        $this->createProjectOwnedBy($this->userLoggedIn('-2 days'), 3);
        $this->createProjectOwnedBy($this->userLoggedIn('-20 days'));
        $this->createProjectOwnedBy($this->userLoggedIn('-45 days'));
        $this->createProjectOwnedBy($this->userLoggedIn(null));
        $this->flushAndClear();

        self::assertSame(['industry' => [1, 2]], $this->deltaOf('industry'));
    }

    public function testAnActiveUserWithoutDataInAnyModuleChangesNoModuleRow(): void
    {
        $this->userLoggedIn('-1 day');
        $this->flushAndClear();

        self::assertSame($this->before, $this->countsByModule());
    }

    public function testAGroupIndustryParticipantNeedsAnotherAcceptedParticipantInTheProject(): void
    {
        $this->createProjectOwnedBy($this->userLoggedIn('-1 day'));
        $groupOwner = $this->userLoggedIn('-1 day');
        $acceptedMember = $this->userLoggedIn('-3 days');
        $this->addParticipant($this->createProjectOwnedBy($groupOwner), $acceptedMember, ParticipantStatus::Accepted);
        $pendingProjectOwner = $this->userLoggedIn('-1 day');
        $this->addParticipant($this->createProjectOwnedBy($pendingProjectOwner), $this->userLoggedIn('-1 day'), ParticipantStatus::Pending);
        $removedProjectOwner = $this->userLoggedIn('-1 day');
        $this->addParticipant($this->createProjectOwnedBy($removedProjectOwner), $this->userLoggedIn('-1 day'), ParticipantStatus::Removed);
        $this->flushAndClear();

        self::assertSame(['groupIndustry' => [2, 2]], $this->deltaOf('groupIndustry'));
    }

    public function testMiningCountsEntriesOwnedOperationsAndMembershipsOncePerUser(): void
    {
        $entryAndOperation = $this->userLoggedIn('-1 day');
        $this->em->persist($this->miningEntryOf($entryAndOperation));
        $this->createMiningOperationOwnedBy($entryAndOperation);
        $memberOnly = $this->userLoggedIn('-1 day');
        $this->addMiningMember($this->createMiningOperationOwnedBy($this->userLoggedIn(null)), $memberOnly);
        $this->flushAndClear();

        self::assertSame(['mining' => [2, 2]], $this->deltaOf('mining'));
    }

    public function testPlanetaryCountsAUserThroughTheColoniesOfItsCharacters(): void
    {
        $user = $this->userLoggedIn('-1 day');
        $this->createCharacter($user, 'Main Pilot');
        $alt = $this->createCharacter($user, 'Alt Pilot');
        $this->em->persist((new PlanetaryColony())
            ->setCharacter($alt)
            ->setPlanetId(40_009_001)
            ->setPlanetType('barren')
            ->setSolarSystemId(30_000_142)
            ->setUpgradeLevel(3)
            ->setNumPins(8)
            ->setLastUpdate(new \DateTimeImmutable('-1 hour')));
        $this->flushAndClear();

        self::assertSame(['planetary' => [1, 1]], $this->deltaOf('planetary'));
    }

    public function testMarketCountsFavouritesAndPriceAlertsOncePerUser(): void
    {
        $favouriteOnly = $this->userLoggedIn('-1 day');
        $this->em->persist((new MarketFavorite())->setUser($favouriteOnly)->setTypeId(16_671));
        $alertOnly = $this->userLoggedIn('-1 day');
        $this->em->persist($this->priceAlertOf($alertOnly, 16_681));
        $both = $this->userLoggedIn('-1 day');
        $this->em->persist((new MarketFavorite())->setUser($both)->setTypeId(16_672));
        $this->em->persist($this->priceAlertOf($both, 16_682));
        $this->flushAndClear();

        self::assertSame(['market' => [3, 3]], $this->deltaOf('market'));
    }

    public function testPveCountsIncomeAndExpensesOncePerUser(): void
    {
        $incomeOnly = $this->userLoggedIn('-1 day');
        $this->em->persist($this->pveIncomeOf($incomeOnly));
        $expenseOnly = $this->userLoggedIn('-1 day');
        $this->em->persist($this->pveExpenseOf($expenseOnly));
        $both = $this->userLoggedIn('-1 day');
        $this->em->persist($this->pveIncomeOf($both));
        $this->em->persist($this->pveExpenseOf($both));
        $this->flushAndClear();

        self::assertSame(['pve' => [3, 3]], $this->deltaOf('pve'));
    }

    private function userLoggedIn(?string $relativeDate): User
    {
        $user = $this->createUser();
        if ($relativeDate !== null) {
            $user->setLastLoginAt(new \DateTimeImmutable($relativeDate));
        }

        return $user;
    }

    private function createProjectOwnedBy(User $owner, int $projectCount = 1): IndustryProject
    {
        for ($index = 0; $index < $projectCount; ++$index) {
            $project = (new IndustryProject())->setOwner($owner)->setName('Hull run');
            $project->enrolOwner();
            $this->em->persist($project);
        }

        return $project;
    }

    private function addParticipant(IndustryProject $project, User $user, ParticipantStatus $status): void
    {
        $project->addMember(
            (new IndustryProjectParticipant())->setUser($user)->setRole(ParticipantRole::Member)->setStatus($status),
        );
    }

    private function miningEntryOf(User $user): MiningEntry
    {
        return (new MiningEntry())
            ->setUser($user)
            ->setCharacterId(93_000_001)
            ->setCharacterName('Maren Esk')
            ->setDate(new \DateTimeImmutable('-1 day'))
            ->setTypeId(1_230)
            ->setTypeName('Veldspar')
            ->setSolarSystemId(30_003_504)
            ->setSolarSystemName('Aldranette')
            ->setQuantity(1_000)
            ->setUnitPrice(12.5);
    }

    private function createMiningOperationOwnedBy(User $owner): MiningOperation
    {
        $operation = (new MiningOperation())
            ->setOwner($owner)
            ->setLabel('Rock cracking')
            ->setPlannedStartAt(new \DateTimeImmutable('+1 day'))
            ->setStatus(MiningOperationStatus::Planned)
            ->setVisibility(MiningOperationVisibility::Corporation)
            ->setSplitMode(MiningSplitMode::ValueProrata)
            ->setCorporationId(98_262_101);
        $this->em->persist($operation);

        return $operation;
    }

    private function addMiningMember(MiningOperation $operation, User $user): void
    {
        $operation->addMember(
            (new MiningOperationMember())
                ->setUser($user)
                ->setRole(MiningOperationMemberRole::Member)
                ->setStatus(MiningOperationMemberStatus::Accepted),
        );
    }

    private function priceAlertOf(User $user, int $typeId): MarketPriceAlert
    {
        return (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId($typeId)
            ->setTypeName(sprintf('Alerted type %d', $typeId))
            ->setDirection(AlertDirection::Below)
            ->setThreshold(1_500.0)
            ->setPriceSource(AlertPriceSource::JitaSell);
    }

    private function pveIncomeOf(User $user): PveIncome
    {
        return (new PveIncome())
            ->setUser($user)
            ->setType(PveIncomeType::Bounty)
            ->setDescription('Bounty prizes')
            ->setAmount(1_000_000.0)
            ->setDate(new \DateTimeImmutable('-1 day'));
    }

    private function pveExpenseOf(User $user): PveExpense
    {
        return (new PveExpense())
            ->setUser($user)
            ->setType(PveExpense::TYPE_AMMO)
            ->setDescription('4000x Republic Fleet EMP M')
            ->setAmount(250_000.0)
            ->setDate(new \DateTimeImmutable('-1 day'));
    }

    /** @return array<string, array{0: ?int, 1: ?int}> */
    private function countsByModule(): array
    {
        $counts = [];
        foreach ($this->adminService->getStats()['moduleActiveUsers'] as $row) {
            $counts[$row['module']] = [$row['activeLast7Days'], $row['activeLast30Days']];
        }

        return $counts;
    }

    /** @return array<string, array{0: int, 1: int}> */
    private function deltaOf(string $module): array
    {
        $after = $this->countsByModule();

        return [$module => [
            $after[$module][0] - $this->before[$module][0],
            $after[$module][1] - $this->before[$module][1],
        ]];
    }
}
