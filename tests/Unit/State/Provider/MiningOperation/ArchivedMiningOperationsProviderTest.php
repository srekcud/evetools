<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationArchive;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\Service\MiningOperation\MiningShareCalculator;
use App\State\Provider\MiningOperation\ArchivedMiningOperationsProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsMiningPayments;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ArchivedMiningOperationsProvider::class)]
#[CoversClass(MiningOperationArchive::class)]
#[Group('issue-265')]
final class ArchivedMiningOperationsProviderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsMiningPayments;

    private const string P2_LABEL = 'Ceinture Veldspar — mardi';
    private const string P2_ARCHIVED_AT = '2026-11-04T22:10:00+00:00';
    private const int P2_ARIA_MINED_VALUE = 812_300;

    private MiningOperation $settledOperation;
    private MiningOperation $completedOperation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->settledOperation = $this->g1OperationWithEveryPaymentPaid();
        $this->settledOperation->settle(new \DateTimeImmutable(self::SETTLED_AT), 'Aria Solberg');
        $this->completedOperation = $this->completedEachForThemselvesOperation();
    }

    public function testArchiveListsTheOperationsInTheRepositoryOrderEachWithItsArchiveSummary(): void
    {
        $archive = $this->archiveSeenBy($this->aria);

        self::assertSame(
            [
                [self::SPODUMAIN_LABEL, 'settled', self::SETTLED_AT, 'Aria Solberg'],
                [self::P2_LABEL, 'completed', self::P2_ARCHIVED_AT, 'Aria Solberg'],
            ],
            array_map(
                static fn (MiningOperationResource $operation): array => [
                    $operation->label,
                    $operation->status,
                    $operation->archivedAt?->format(\DateTimeInterface::ATOM),
                    $operation->archivedByName,
                ],
                $archive,
            ),
        );
    }

    public function testSettledOperationSummaryGivesTheTotalValueMyMinedValueMyShareAndThePaidPayments(): void
    {
        self::assertSame(
            [
                'archivedAt' => self::SETTLED_AT,
                'archivedByName' => 'Aria Solberg',
                'totalValue' => self::E1_TOTAL_VALUE,
                'myMinedValue' => self::ARIA_MINED_VALUE,
                'myShare' => 2_294_229,
                'paymentsCount' => 3,
                'paidPaymentsCount' => 3,
            ],
            $this->archiveSeenBy($this->aria)[0]->archiveSummary ?? null,
        );
    }

    public function testBoosterSummaryGivesHerShareRecomputedOnTheFrozenValues(): void
    {
        $summary = $this->archiveSeenBy($this->cyra)[0]->archiveSummary ?? [];

        self::assertSame([self::CYRA_MINED_VALUE, 150], [$summary['myMinedValue'] ?? null, $summary['myShare'] ?? null]);
    }

    public function testCompletedOperationSummaryGivesMyMinedValueWithoutShareNorPayment(): void
    {
        self::assertSame(
            [
                'archivedAt' => self::P2_ARCHIVED_AT,
                'archivedByName' => 'Aria Solberg',
                'totalValue' => self::P2_ARIA_MINED_VALUE + 1_000,
                'myMinedValue' => self::P2_ARIA_MINED_VALUE,
                'myShare' => null,
                'paymentsCount' => 0,
                'paidPaymentsCount' => 0,
            ],
            $this->archiveSeenBy($this->aria)[1]->archiveSummary ?? null,
        );
    }

    public function testCorporationMemberWhoDidNotTakePartHasNeitherMinedValueNorShare(): void
    {
        $summary = $this->archiveSeenBy($this->elia)[0]->archiveSummary ?? [];

        self::assertSame(
            [self::E1_TOTAL_VALUE, null, null],
            [$summary['totalValue'] ?? 'missing', $summary['myMinedValue'] ?? null, $summary['myShare'] ?? null],
        );
    }

    private function completedEachForThemselvesOperation(): MiningOperation
    {
        $operation = $this->operationOwnedBy($this->aria, self::P2_LABEL, self::C1, '2b2b2b2b2b')->setSplitMode(MiningSplitMode::EachForThemselves);
        $aria = $this->addMembership($operation, $this->aria, MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted, []);
        $elia = $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $operation->complete(
            new \DateTimeImmutable(self::P2_ARCHIVED_AT),
            new \DateTimeImmutable('2026-11-04T22:00:00+00:00'),
            [],
            [
                new MiningFrozenMinedValue($operation, $aria, 'Aria Solberg', 0, (float) self::P2_ARIA_MINED_VALUE),
                new MiningFrozenMinedValue($operation, $elia, 'Elia Varr', 1, 1_000.0),
            ],
            'Aria Solberg',
        );

        return $operation;
    }

    private function archiveSeenBy(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $repository = $this->createMock(MiningOperationRepository::class);
        $repository->expects($this->once())->method('findArchivedReadableBy')->with($user)->willReturn([$this->settledOperation, $this->completedOperation]);

        $provider = new ArchivedMiningOperationsProvider(
            $security,
            $repository,
            new MiningOperationArchive(new MiningShareCalculator()),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
        );

        return $provider->provide(new GetCollection());
    }
}
