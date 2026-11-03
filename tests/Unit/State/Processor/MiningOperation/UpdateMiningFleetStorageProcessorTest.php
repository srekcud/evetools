<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\MiningOperation\UpdateMiningFleetStorageInput;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningFleetStorageDeclarations;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\State\Processor\MiningOperation\UpdateMiningFleetStorageProcessor;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(UpdateMiningFleetStorageProcessor::class)]
#[CoversClass(MiningFleetStorageDeclarations::class)]
#[CoversClass(MiningOperation::class)]
#[Group('issue-266')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateMiningFleetStorageProcessorTest extends TestCase
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
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testOwnerChoosesTheRecipientAndTheContainerAndEveryParticipantIsNotified(): void
    {
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update(['recipientMemberId' => $this->memberIdOf($this->brann), 'containerName' => self::CONTAINER_NAME]);

        self::assertSame(
            [
                'resource' => [$this->memberIdOf($this->brann), 'Brann Holt', self::CONTAINER_NAME],
                'operation' => [true, self::CONTAINER_NAME],
                'messages' => $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $this->operation, 'fleet_storage_updated', ['memberId' => null]),
            ],
            [
                'resource' => [$resource->recipientMemberId, $resource->recipientName, $resource->containerName],
                'operation' => [$this->operation->getFleetStorageRecipient() === $this->membershipOf($this->operation, $this->brann), $this->operation->getFleetStorageContainerName()],
                'messages' => $this->publishedMessages($publishedUpdates),
            ],
        );
    }

    public function testAbsentFieldsAreLeftUnchanged(): void
    {
        $this->actAs($this->aria);
        $this->update(['recipientMemberId' => $this->memberIdOf($this->brann), 'containerName' => self::CONTAINER_NAME]);

        $renamed = $this->update(['containerName' => 'OPS-HAUL-2']);
        $withoutRecipient = $this->update(['recipientMemberId' => null]);

        self::assertSame(
            [['Brann Holt', 'OPS-HAUL-2'], [null, null, 'OPS-HAUL-2']],
            [[$renamed->recipientName, $renamed->containerName], [$withoutRecipient->recipientMemberId, $withoutRecipient->recipientName, $withoutRecipient->containerName]],
        );
    }

    public function testAdminChoosesTheRecipient(): void
    {
        $this->membershipOf($this->operation, $this->cyra)->setRole(MiningOperationMemberRole::Admin);
        $this->actAs($this->cyra);

        self::assertSame('Cyra Lune', $this->update(['recipientMemberId' => $this->memberIdOf($this->cyra)])->recipientName);
    }

    public function testPendingPilotCannotBeTheRecipient(): void
    {
        $this->membershipOf($this->operation, $this->cyra)->setStatus(MiningOperationMemberStatus::Pending);
        $this->actAs($this->aria);

        self::assertSame(
            ['The fleet storage recipient must be an accepted participant', null],
            [$this->refusalOf(['recipientMemberId' => $this->memberIdOf($this->cyra)]), $this->operation->getFleetStorageRecipient()],
        );
    }

    public function testMemberOfAnotherOperationCannotBeTheRecipient(): void
    {
        $this->actAs($this->aria);
        $memberOfAnotherOperation = $this->addMembership($this->iceBeltOperation(), $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Elia Varr']);

        self::assertSame(
            'The fleet storage recipient must be an accepted participant',
            $this->refusalOf(['recipientMemberId' => $memberOfAnotherOperation->getId()->toRfc4122()]),
        );
    }

    public function testParticipantWhoBecomesTheRecipientNoLongerDeliversButTheOthersKeepTheirDeclaration(): void
    {
        $this->actAs($this->aria);
        $this->membershipOf($this->operation, $this->aria)->declareFleetStorage(MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 100_000]);
        $this->membershipOf($this->operation, $this->cyra)->declareFleetStorage(MiningFleetStorageDelivery::All, null);
        $this->update(['recipientMemberId' => $this->memberIdOf($this->brann)]);

        $resource = $this->update(['recipientMemberId' => $this->memberIdOf($this->aria)]);

        self::assertSame(
            ['recipientName' => 'Aria Solberg', 'aria' => ['none', null], 'cyra' => ['all', null]],
            [
                'recipientName' => $resource->recipientName,
                'aria' => $this->declarationOf($this->aria),
                'cyra' => $this->declarationOf($this->cyra),
            ],
        );
    }

    public function testEachForThemselvesOperationHasNoFleetStorage(): void
    {
        $this->operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->actAs($this->aria);

        self::assertSame('An each-for-themselves operation has no fleet storage', $this->refusalOf(['containerName' => self::CONTAINER_NAME]));
    }

    public function testFrozenSharesKeepTheirFleetStorage(): void
    {
        $this->operation->setStatus(MiningOperationStatus::Shares);
        $this->actAs($this->aria);

        self::assertSame('The shares of this operation are frozen', $this->refusalOf(['recipientMemberId' => $this->memberIdOf($this->brann)]));
    }

    public function testEachForThemselvesRefusalComesBeforeTheFrozenSharesOne(): void
    {
        $this->operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->operation->setStatus(MiningOperationStatus::Completed);
        $this->actAs($this->aria);

        self::assertSame('An each-for-themselves operation has no fleet storage', $this->refusalOf(['containerName' => self::CONTAINER_NAME]));
    }

    public function testFleetStorageIsChosenWhenTheMercureHubIsDownAndTheFailureIsLogged(): void
    {
        $this->actAs($this->aria);
        $this->hub->method('publish')->willThrowException(new RuntimeException('Mercure hub unreachable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning')->with('Failed to publish mining operation Mercure update');

        $resource = $this->update(['recipientMemberId' => $this->memberIdOf($this->brann)], $logger);

        self::assertSame('Brann Holt', $resource->recipientName);
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

    private function refusalOf(array $fields): ?string
    {
        try {
            $this->update($fields);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function update(array $fields, ?LoggerInterface $logger = null): MiningFleetStorageResource
    {
        $input = new UpdateMiningFleetStorageInput();
        foreach ($fields as $name => $value) {
            $input->{$name} = $value;
        }
        $processor = new UpdateMiningFleetStorageProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningFleetStorageDeclarations($this->entityManager),
            new MiningOperationAccess(),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock())),
            new MercurePublisherService($this->hub, $logger ?? new NullLogger()),
        );

        return $processor->process($input, new Patch(), ['operationId' => $this->operation->getId()->toRfc4122()]);
    }
}
