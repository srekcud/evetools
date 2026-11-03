<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\MiningOperation\CorrectMinedQuantityInput;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningQuantityCorrection;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\TypeNameResolver;
use App\State\Processor\MiningOperation\CorrectMinedQuantityProcessor;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(CorrectMinedQuantityProcessor::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class CorrectMinedQuantityProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const int UNKNOWN_TYPE = 999_999_263;
    private const string CORRECTED_AT = '2026-11-05T21:50:00+00:00';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MockClock $clock;
    private MiningOperation $operation;
    private CorrectMinedQuantityProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500)]);
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->takenStartSnapshot('Aria II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 6_000)]);
        $this->waitingEndSnapshot('Aria II', '2026-11-05T21:59:00+00:00');

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => [self::SPODUMAIN => 'Spodumain', self::BISTOT => 'Bistot'][$typeId] ?? null);

        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->clock = new MockClock(self::CORRECTED_AT);
        $this->processor = new CorrectMinedQuantityProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->operationCharacterRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MinedQuantityCalculator(),
            $typeNameResolver,
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
            $this->clock,
        );
    }

    public function testOwnerCorrectsTheUnknownQuantityOfBrannIIAndEveryParticipantIsNotified(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $this->correct($this->enrolmentOf('Brann II'), self::SPODUMAIN, 20_000);

        self::assertSame([[self::SPODUMAIN, null, 20_000, 'Aria Solberg', self::CORRECTED_AT]], $this->correctionsOf($this->enrolmentOf('Brann II')));
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann], $this->operation, 'quantity_corrected', [
                'operationCharacterId' => $this->enrolmentOf('Brann II')->getId()->toRfc4122(),
                'typeId' => self::SPODUMAIN,
            ]),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testEachCorrectionKeepsTheMeasuredQuantityAsItsOriginal(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->aria);

        $this->correct($this->enrolmentOf('Brann Holt'), self::SPODUMAIN, 30_000);
        $this->clock->modify('2026-11-05T21:52:00+00:00');
        $this->correct($this->enrolmentOf('Brann Holt'), self::SPODUMAIN, 33_000);

        self::assertSame(
            [
                [self::SPODUMAIN, 35_500, 30_000, 'Aria Solberg', self::CORRECTED_AT],
                [self::SPODUMAIN, 35_500, 33_000, 'Aria Solberg', '2026-11-05T21:52:00+00:00'],
            ],
            $this->correctionsOf($this->enrolmentOf('Brann Holt')),
        );
    }

    public function testCorrectionOfATypeMeasuredWithoutLedgerEntryHasAnOriginalOfZero(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->aria);

        $this->correct($this->enrolmentOf('Brann Holt'), self::BISTOT, 1_000);

        self::assertSame([[self::BISTOT, 0, 1_000, 'Aria Solberg', self::CORRECTED_AT]], $this->correctionsOf($this->enrolmentOf('Brann Holt')));
    }

    public function testPlainMemberCannotCorrectAQuantity(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectRefusal(AccessDeniedHttpException::class, 'Only the operation owner or an admin can perform this action');

        $this->correct($this->enrolmentOf('Brann II'), self::SPODUMAIN, 20_000);
    }

    public function testQuantityCannotBeCorrectedWhileTheOperationIsInProgress(): void
    {
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Quantities can only be corrected while the operation is closing');

        $this->correct($this->enrolmentOf('Brann II'), self::SPODUMAIN, 20_000);
    }

    public function testQuantityStillBeingMeasuredCannotBeCorrected(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'This quantity is still being measured');

        $this->correct($this->enrolmentOf('Aria II'), self::SPODUMAIN, 9_000);
    }

    public function testTypeUnknownFromTheStaticDataIsRefused(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(BadRequestHttpException::class, 'Unknown type');

        $this->correct($this->enrolmentOf('Brann II'), self::UNKNOWN_TYPE, 20_000);
    }

    public function testCharacterOfAnotherOperationIsNotFound(): void
    {
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $iceBelt = $this->iceBeltOperation();
        $this->addMembership($iceBelt, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Dorn Achar']);
        $this->identifyEnrolments();
        $this->actAs($this->aria);
        $this->expectNothingChanged();

        $this->expectRefusal(NotFoundHttpException::class, 'Character not found');

        $this->correct($this->enrolmentOf('Dorn Achar', $iceBelt), self::SPODUMAIN, 20_000);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function correct(MiningOperationCharacter $enrolment, int $typeId, int $quantity): void
    {
        $input = new CorrectMinedQuantityInput();
        $input->typeId = $typeId;
        $input->quantity = $quantity;

        $this->processor->process($input, new Post(), [
            'operationId' => $this->operation->getId()->toRfc4122(),
            'id' => $enrolment->getId()->toRfc4122(),
        ]);
    }

    private function correctionsOf(MiningOperationCharacter $enrolment): array
    {
        return array_map(static fn (MiningQuantityCorrection $correction): array => [
            $correction->getTypeId(),
            $correction->getOriginalQuantity(),
            $correction->getCorrectedQuantity(),
            $correction->getCorrectedByName(),
            $correction->getCorrectedAt()->format(\DateTimeInterface::ATOM),
        ], array_values($enrolment->getCorrections()->toArray()));
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }

    private function expectRefusal(string $exceptionClass, string $message): void
    {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($message);
    }
}
