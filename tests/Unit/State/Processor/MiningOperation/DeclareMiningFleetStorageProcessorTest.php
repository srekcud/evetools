<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\MiningOperation\DeclareMiningFleetStorageInput;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningFleetStorageDeclarations;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Processor\MiningOperation\DeclareMiningFleetStorageProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(DeclareMiningFleetStorageProcessor::class)]
#[CoversClass(MiningFleetStorageDeclarations::class)]
#[CoversClass(MiningOperationMember::class)]
#[Group('issue-266')]
#[AllowMockObjectsWithoutExpectations]
final class DeclareMiningFleetStorageProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string CONTAINER_NAME = 'OPS-HAUL';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->e1Operation();
        $this->operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, boosterMember: $this->membershipOf($this->operation, $this->cyra));
        $this->operation->assignFleetStorage($this->membershipOf($this->operation, $this->brann), self::CONTAINER_NAME);
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testParticipantDeclaresThePartOfHerOreSheDeliversAndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->declare($this->aria, 'partial', [['typeId' => self::VELDSPAR, 'quantity' => 100_000]]);

        self::assertSame(
            [
                'declaration' => [
                    'memberId' => $this->memberIdOf($this->aria),
                    'characterName' => 'Aria Solberg',
                    'delivery' => 'partial',
                    'quantities' => [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'quantity' => 100_000]],
                    'canDeclare' => true,
                ],
                'member' => ['partial', [self::VELDSPAR => 100_000]],
                'messages' => $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'fleet_storage_updated', ['memberId' => $this->memberIdOf($this->aria)]),
            ],
            [
                'declaration' => $resource->declarations[0] ?? null,
                'member' => $this->declarationOf($this->aria),
                'messages' => $this->publishedMessages($publishedUpdates),
            ],
        );
    }

    public function testPlainMemberDeclaresSheDeliversEverything(): void
    {
        $this->actAs($this->cyra);

        $resource = $this->declare($this->cyra, 'all');

        self::assertSame(
            ['resource' => ['Cyra Lune', 'all', []], 'member' => ['all', null]],
            ['resource' => $this->resourceDeclarationOf($resource, 'Cyra Lune'), 'member' => $this->declarationOf($this->cyra)],
        );
    }

    public function testOwnerDeclaresForAnotherParticipant(): void
    {
        $this->actAs($this->aria);

        $this->declare($this->cyra, 'all');

        self::assertSame(['all', null], $this->declarationOf($this->cyra));
    }

    public function testDeliveringEverythingAfterAPartialDeclarationForgetsTheQuantities(): void
    {
        $this->actAs($this->aria);
        $this->declare($this->aria, 'partial', [['typeId' => self::VELDSPAR, 'quantity' => 10_000], ['typeId' => self::BITUMENS, 'quantity' => 3_000]]);

        $this->declare($this->aria, 'all');

        self::assertSame(['all', null], $this->declarationOf($this->aria));
    }

    public function testDeliveredQuantityAboveWhatWasMinedIsAcceptedWhileTheMiningIsMeasured(): void
    {
        $this->actAs($this->aria);

        $this->declare($this->aria, 'partial', [['typeId' => self::VELDSPAR, 'quantity' => 100_001]]);

        self::assertSame(['partial', [self::VELDSPAR => 100_001]], $this->declarationOf($this->aria));
    }

    public function testRecipientCannotDeliverToHimself(): void
    {
        $this->actAs($this->aria);

        self::assertSame(
            ['The fleet storage recipient keeps the ore delivered to them', ['none', null]],
            [$this->refusalOf($this->brann, 'all'), $this->declarationOf($this->brann)],
        );
    }

    public function testDeliveringWithoutRecipientIsRefusedButKeepingEverythingIsAccepted(): void
    {
        $this->operation->assignFleetStorage(null, null);
        $this->actAs($this->aria);

        $refusal = $this->refusalOf($this->aria, 'all');
        $resource = $this->declare($this->aria, 'none');

        self::assertSame(
            ['Choose the fleet storage recipient first', ['Aria Solberg', 'none', []]],
            [$refusal, $this->resourceDeclarationOf($resource, 'Aria Solberg')],
        );
    }

    public function testPendingPilotDeclaresNoFleetStorage(): void
    {
        $this->membershipOf($this->operation, $this->cyra)->setStatus(MiningOperationMemberStatus::Pending);
        $this->actAs($this->aria);

        self::assertSame('Only an accepted participant declares a fleet storage', $this->refusalOf($this->cyra, 'all'));
    }

    public function testEachForThemselvesOperationHasNoFleetStorage(): void
    {
        $this->operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->actAs($this->aria);

        self::assertSame('An each-for-themselves operation has no fleet storage', $this->refusalOf($this->aria, 'all'));
    }

    public function testDeclarationOfFrozenSharesCannotChange(): void
    {
        $this->operation->setStatus(MiningOperationStatus::Shares);
        $this->actAs($this->aria);
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        self::assertSame(
            ['The shares of this operation are frozen', ['none', null]],
            [$this->refusalOf($this->aria, 'all'), $this->declarationOf($this->aria)],
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function memberIdOf(User $user): string
    {
        return $this->membershipOf($this->operation, $user)->getId()->toRfc4122();
    }

    private function declarationOf(User $user): array
    {
        $member = $this->membershipOf($this->operation, $user);

        return [$member->getFleetStorageDelivery()->value, $member->getFleetStorageQuantities()];
    }

    private function resourceDeclarationOf(MiningFleetStorageResource $resource, string $characterName): ?array
    {
        foreach ($resource->declarations as $declaration) {
            if ($declaration['characterName'] === $characterName) {
                return [$declaration['characterName'], $declaration['delivery'], $declaration['quantities']];
            }
        }

        return null;
    }

    private function refusalOf(User $participant, string $delivery, ?array $quantities = null): ?string
    {
        try {
            $this->declare($participant, $delivery, $quantities);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function declare(User $participant, string $delivery, ?array $quantities = null): MiningFleetStorageResource
    {
        $input = new DeclareMiningFleetStorageInput();
        $input->delivery = $delivery;
        $input->quantities = $quantities;
        $processor = new DeclareMiningFleetStorageProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningFleetStorageDeclarations($this->entityManager),
            new MiningOperationAccess(),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        return $processor->process($input, new Patch(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'memberId' => $this->memberIdOf($participant),
        ]);
    }
}
