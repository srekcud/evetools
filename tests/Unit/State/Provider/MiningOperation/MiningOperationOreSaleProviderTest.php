<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Mining\OreSaleResource;
use App\Entity\MiningOperation;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mining\OreSaleService;
use App\Service\MiningOperation\MinedOreCompressedQuantities;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Provider\MiningOperation\MiningOperationOreSaleProvider;
use App\Tests\Unit\Service\Mining\BuildsOreSaleServiceOnHeldOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MiningOperationOreSaleProvider::class)]
#[Group('issue-314')]
final class MiningOperationOreSaleProviderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreSaleServiceOnHeldOres;

    private const int VELDSPAR = 1230;
    private const int MYSTERY_ORE = 99_314_001;
    private const float VALUE_DELTA = 1e-9;

    private MiningOperation $operation;
    private ?User $actor = null;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperationWithBrann();
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->identifyEnrolments();
    }

    public function testAParticipantSeesTheCompressedEquivalentOfOnlyHisOwnMinedQuantitiesValuedOnTheMarket(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->aria);

        $sale = $this->provide();

        self::assertSame($this->operation->getId()->toRfc4122(), $sale->operationId);
        self::assertNull($sale->structureId);
        self::assertSame([self::COMPRESSED_VELDSPAR], array_column($sale->rows, 'typeId'));
        self::assertSame([400], array_column($sale->rows, 'quantity'));
        self::assertSame(['jita_sell'], array_column($sale->rows, 'bestSource'));
        self::assertEqualsWithDelta(400 * 19.0, $sale->totals['totalValue'], self::VALUE_DELTA);
        self::assertSame([], $sale->unconvertedTypes);
    }

    public function testTheStructureOfTheQueryAddsItsPrices(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->aria);

        $sale = $this->provide(['structureId' => (string) self::SALE_STRUCTURE_ID]);

        self::assertSame(self::SALE_STRUCTURE_ID, $sale->structureId);
        self::assertSame(['structure_buy'], array_column($sale->rows, 'bestSource'));
        self::assertEqualsWithDelta(400 * 21.0, $sale->totals['totalValue'], self::VALUE_DELTA);
    }

    public function testAMinedTypeThatCannotBeCompressedIsListedAndNeverValued(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter(extraMined: [self::MYSTERY_ORE => 7_000]);
        $this->actAs($this->aria);

        $sale = $this->provide();

        self::assertSame([self::COMPRESSED_VELDSPAR], array_column($sale->rows, 'typeId'));
        self::assertSame([['typeId' => self::MYSTERY_ORE, 'typeName' => null]], $sale->unconvertedTypes);
    }

    public function testAParticipantWhoseQuantitiesAreNotKnownGetsAConflictInsteadOfAnEmptySale(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->brann);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('The mined quantities of this operation are not known yet');

        $this->provide();
    }

    public function testAStrangerToTheOperationIsRefused(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->dorn);

        $this->expectException(AccessDeniedHttpException::class);

        $this->provide();
    }

    public function testAPendingInviteeIsRefused(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->cyra);

        $this->expectException(AccessDeniedHttpException::class);

        $this->provide();
    }

    public function testAnIdentifierThatIsNotAnOperationIsNotFound(): void
    {
        $this->actAs($this->aria);

        $this->expectException(NotFoundHttpException::class);

        $this->provide(operationId: 'abc');
    }

    public function testAStructureIdThatIsNotAPositiveIntegerIsABadRequest(): void
    {
        $this->closedWithAriaMiningTwentyThousandVeldsparPerCharacter();
        $this->actAs($this->aria);

        $this->expectException(BadRequestHttpException::class);

        $this->provide(['structureId' => '0']);
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $this->actor = null;

        $this->expectException(UnauthorizedHttpException::class);

        $this->provide();
    }

    private function closedWithAriaMiningTwentyThousandVeldsparPerCharacter(array $extraMined = []): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 12_000)]);
        }
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        foreach (['Aria Solberg', 'Aria II'] as $characterName) {
            $entries = [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 32_000)];
            foreach ($extraMined as $typeId => $quantity) {
                $entries[] = $this->entry('2026-11-05', $typeId, self::S1, $quantity);
            }
            $this->takenEndSnapshot($characterName, $entries);
        }
        $this->identifyEnrolments();
    }

    private function actAs(User $user): void
    {
        $this->actor = $user;
    }

    private function provide(array $query = [], ?string $operationId = null): OreSaleResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->actor);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects(self::never())->method('getAggregatedQuantitiesByUser');

        $provider = new MiningOperationOreSaleProvider(
            $security,
            $requestStack,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MinedQuantityCalculator(),
            new MinedOreCompressedQuantities($this->sdeWithVeldsparAndItsCompressedVariant()),
            new OreSaleService(
                $cachedAssetRepository,
                $this->compressedMineableTypeRepository([]),
                $this->jitaMarketWithFallback(
                    [self::COMPRESSED_VELDSPAR => 17.0],
                    [self::COMPRESSED_VELDSPAR => 19.0],
                ),
                $this->saleStructureMarket([self::COMPRESSED_VELDSPAR => 21.0], []),
            ),
        );

        return $provider->provide(new Get(), ['operationId' => $operationId ?? $this->operation->getId()->toRfc4122()]);
    }

    private function sdeWithVeldsparAndItsCompressedVariant(): InvTypeRepository
    {
        $veldspar = (new InvType())->setTypeId(self::VELDSPAR)->setTypeName('Veldspar')->setPortionSize(100)->setPublished(true);
        $compressedVeldspar = (new InvType())->setTypeId(self::COMPRESSED_VELDSPAR)->setTypeName('Compressed Veldspar')->setPortionSize(1)->setPublished(true);
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => in_array(self::VELDSPAR, $typeIds, true) ? [self::VELDSPAR => $veldspar] : [],
        );
        $repository->method('findByNames')->willReturnCallback(
            static fn (array $names): array => in_array('Compressed Veldspar', $names, true) ? ['Compressed Veldspar' => $compressedVeldspar] : [],
        );

        return $repository;
    }
}
