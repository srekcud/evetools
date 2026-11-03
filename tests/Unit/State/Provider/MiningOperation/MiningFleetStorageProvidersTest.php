<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\Service\MiningOperation\MiningShareFreezer;
use App\State\Provider\MiningOperation\MiningFleetStorageDeclarationProvider;
use App\State\Provider\MiningOperation\MiningFleetStorageProvider;
use App\State\Provider\MiningOperation\MiningFleetStorageVerificationProvider;
use App\State\Provider\MiningOperation\MiningOperationInvitationProvider;
use App\State\Provider\MiningOperation\MiningOperationProvider;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\State\Provider\MiningOperation\MiningOperationValuationProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsVisibleCorporationAssets;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningFleetStorageProvider::class)]
#[CoversClass(MiningFleetStorageDeclarationProvider::class)]
#[CoversClass(MiningFleetStorageVerificationProvider::class)]
#[CoversClass(MiningOperationResourceMapper::class)]
#[Group('issue-266')]
#[AllowMockObjectsWithoutExpectations]
final class MiningFleetStorageProvidersTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsVisibleCorporationAssets;

    private const string READ_AT = '2026-11-05T21:50:00+00:00';

    private Security&Stub $security;
    private MockClock $clock;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->setUpVisibleCorporationAssets();
        $this->security = $this->createStub(Security::class);
        $this->clock = new MockClock(self::READ_AT);
        $this->operation = $this->fs2Operation();
    }

    public function testParticipantReadsTheRecipientTheContainerAndEveryDeclarationInLedgerOrder(): void
    {
        $this->actAs($this->brann);

        self::assertSame(
            [
                'operationId' => $this->operation->getId()->toRfc4122(),
                'available' => true,
                'editable' => true,
                'recipientMemberId' => $this->memberIdOf($this->brann),
                'recipientName' => 'Brann Holt',
                'containerName' => self::CONTAINER_NAME,
                'declarations' => [
                    [
                        'memberId' => $this->memberIdOf($this->aria),
                        'characterName' => 'Aria Solberg',
                        'delivery' => 'partial',
                        'quantities' => [['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'quantity' => 100_000]],
                        'canDeclare' => false,
                    ],
                    ['memberId' => $this->memberIdOf($this->brann), 'characterName' => 'Brann Holt', 'delivery' => 'none', 'quantities' => [], 'canDeclare' => false],
                    ['memberId' => $this->memberIdOf($this->cyra), 'characterName' => 'Cyra Lune', 'delivery' => 'none', 'quantities' => [], 'canDeclare' => false],
                ],
            ],
            $this->fleetStorageToArray($this->fleetStorage(new Get())),
        );
    }

    public function testOwnerMayDeclareForEveryParticipantButTheRecipient(): void
    {
        $this->actAs($this->aria);

        self::assertSame(
            ['Aria Solberg' => true, 'Brann Holt' => false, 'Cyra Lune' => true],
            $this->canDeclareOf($this->fleetStorage(new Get())),
        );
    }

    public function testPlainMemberMayDeclareOnlyForHerself(): void
    {
        $this->actAs($this->cyra);

        self::assertSame(
            ['Aria Solberg' => false, 'Brann Holt' => false, 'Cyra Lune' => true],
            $this->canDeclareOf($this->fleetStorage(new Get())),
        );
    }

    public function testEachForThemselvesOperationHasNoFleetStorage(): void
    {
        $this->operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $this->actAs($this->aria);

        $fleetStorage = $this->fleetStorage(new Get());

        self::assertSame(['available' => false, 'editable' => false], ['available' => $fleetStorage->available, 'editable' => $fleetStorage->editable]);
    }

    public function testFleetStorageOfFrozenSharesIsNoLongerEditable(): void
    {
        $this->freeze();
        $this->actAs($this->aria);

        $fleetStorage = $this->fleetStorage(new Get());

        self::assertSame(
            ['available' => true, 'editable' => false, 'recipientName' => 'Brann Holt', 'canDeclare' => ['Aria Solberg' => false, 'Brann Holt' => false, 'Cyra Lune' => false]],
            ['available' => $fleetStorage->available, 'editable' => $fleetStorage->editable, 'recipientName' => $fleetStorage->recipientName, 'canDeclare' => $this->canDeclareOf($fleetStorage)],
        );
    }

    public function testPilotWithoutAccessCannotReadTheFleetStorage(): void
    {
        $this->actAs($this->dorn);

        self::assertSame([403, 'You do not have access to this operation'], $this->refusalOf(fn () => $this->fleetStorage(new Get())));
    }

    public function testPlainMemberCannotReachTheFleetStorageUpdate(): void
    {
        $this->actAs($this->brann);

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->refusalOf(fn () => $this->fleetStorage(new Patch())));
    }

    public function testParticipantReachesTheDeclarationOfHerOwnLine(): void
    {
        $this->actAs($this->cyra);

        $fleetStorage = $this->declarationFor($this->memberIdOf($this->cyra));

        self::assertSame(
            [$this->operation->getId()->toRfc4122(), 'Brann Holt'],
            [$fleetStorage->operationId, $fleetStorage->recipientName],
        );
    }

    public function testAdminReachesTheDeclarationOfAnotherParticipant(): void
    {
        $this->membershipOf($this->operation, $this->brann)->setRole(MiningOperationMemberRole::Admin);
        $this->actAs($this->brann);

        self::assertSame($this->operation->getId()->toRfc4122(), $this->declarationFor($this->memberIdOf($this->cyra))->operationId);
    }

    public function testPlainMemberCannotDeclareForAnotherParticipant(): void
    {
        $this->actAs($this->cyra);

        self::assertSame(
            [403, 'Only the participant, the operation owner or an admin can declare this fleet storage'],
            $this->refusalOf(fn () => $this->declarationFor($this->memberIdOf($this->aria))),
        );
    }

    public function testPilotWithoutAccessCannotDeclare(): void
    {
        $this->actAs($this->dorn);

        self::assertSame([403, 'You do not have access to this operation'], $this->refusalOf(fn () => $this->declarationFor($this->memberIdOf($this->aria))));
    }

    public function testMemberUnknownToTheOperationIsNotFoundBeforeAnyRightIsChecked(): void
    {
        $this->actAs($this->cyra);
        $otherOperation = $this->iceBeltOperation();
        $memberOfAnotherOperation = $this->addMembership($otherOperation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Elia Varr']);

        self::assertSame(
            [[404, 'Member not found'], [404, 'Member not found'], [404, 'Member not found']],
            [
                $this->refusalOf(fn () => $this->declarationFor(Uuid::v4()->toRfc4122())),
                $this->refusalOf(fn () => $this->declarationFor($memberOfAnotherOperation->getId()->toRfc4122())),
                $this->refusalOf(fn () => $this->declarationFor(self::NOT_A_UUID)),
            ],
        );
    }

    public function testParticipantReadsTheVerificationOfTheNamedContainer(): void
    {
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 96_400]);
        $this->actAs($this->aria);

        $verification = $this->verificationProvider()->provide(new Get(), ['operationId' => $this->operation->getId()->toRfc4122()]);

        self::assertSame(
            [
                'operationId' => $this->operation->getId()->toRfc4122(),
                'status' => 'short',
                'unknownReason' => null,
                'containerName' => self::CONTAINER_NAME,
                'assetsReadAt' => self::ASSETS_READ_AT,
                'lines' => [[
                    'typeId' => self::VELDSPAR,
                    'typeName' => 'Veldspar',
                    'expectedQuantity' => 100_000,
                    'foundQuantity' => 96_400,
                    'missingQuantity' => 3_600,
                    'status' => 'short',
                ]],
            ],
            [
                'operationId' => $verification->operationId,
                'status' => $verification->status,
                'unknownReason' => $verification->unknownReason,
                'containerName' => $verification->containerName,
                'assetsReadAt' => $verification->assetsReadAt?->format(\DateTimeInterface::ATOM),
                'lines' => $verification->lines,
            ],
        );
    }

    public function testPilotWithoutAccessCannotReadTheVerification(): void
    {
        $this->actAs($this->dorn);

        self::assertSame(
            [403, 'You do not have access to this operation'],
            $this->refusalOf(fn () => $this->verificationProvider()->provide(new Get(), ['operationId' => $this->operation->getId()->toRfc4122()])),
        );
    }

    public function testValuationNamesTheRecipientAndGivesEachParticipantHisDeclarationKeptValueAndDebt(): void
    {
        $this->actAs($this->aria);

        $valuation = $this->valuationProvider()->provide(new Get(), ['operationId' => $this->operation->getId()->toRfc4122()]);

        self::assertSame(
            [
                'fleetStorageRecipientMemberId' => $this->memberIdOf($this->brann),
                'fleetStorageRecipientName' => 'Brann Holt',
                'participants' => [
                    'Aria Solberg' => ['partial', false, 1_961_000, 738_093, 1_691_091],
                    'Brann Holt' => ['none', true, 0, self::BRANN_MINED_VALUE, 0],
                    'Cyra Lune' => ['none', false, 0, self::CYRA_MINED_VALUE, 0],
                ],
            ],
            [
                'fleetStorageRecipientMemberId' => $valuation->fleetStorageRecipientMemberId,
                'fleetStorageRecipientName' => $valuation->fleetStorageRecipientName,
                'participants' => array_combine(
                    array_column($valuation->participants, 'characterName'),
                    array_map(static fn (array $participant): array => [
                        $participant['fleetStorageDelivery'] ?? 'absent',
                        $participant['isFleetStorageRecipient'] ?? 'absent',
                        $participant['deliveredValue'] ?? 'absent',
                        $participant['keptValue'] ?? 'absent',
                        $participant['fleetStorageDebt'] ?? 'absent',
                    ], $valuation->participants),
                ),
            ],
        );
    }

    public function testOperationAndInvitationNameTheFleetStorageRecipient(): void
    {
        $this->actAs($this->brann);

        $operation = (new MiningOperationProvider($this->security, $this->operationRepositoryReadingOperations(), new MiningOperationAccess(), new MiningOperationResourceMapper()))
            ->provide(new Get(), ['id' => $this->operation->getId()->toRfc4122()]);
        $invitation = (new MiningOperationInvitationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->createStub(EntityManagerInterface::class)),
            new MiningOperationResourceMapper(),
        ))->provide(new Get(), ['code' => self::JOIN_CODE]);

        self::assertSame(['Brann Holt', 'Brann Holt'], [$operation->fleetStorageRecipientName, $invitation->fleetStorageRecipientName]);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function memberIdOf(User $user): string
    {
        return $this->membershipOf($this->operation, $user)->getId()->toRfc4122();
    }

    private function fleetStorage(Get|Patch $apiOperation): MiningFleetStorageResource
    {
        return (new MiningFleetStorageProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
        ))->provide($apiOperation, ['operationId' => $this->operation->getId()->toRfc4122()]);
    }

    private function declarationFor(string $memberId): MiningFleetStorageResource
    {
        return (new MiningFleetStorageDeclarationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
        ))->provide(new Patch(), ['operationId' => $this->operation->getId()->toRfc4122(), 'memberId' => $memberId]);
    }

    private function verificationProvider(): MiningFleetStorageVerificationProvider
    {
        return new MiningFleetStorageVerificationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->verifier(),
            new MiningOperationResourceMapper(),
        );
    }

    private function valuationProvider(): MiningOperationValuationProvider
    {
        return new MiningOperationValuationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
        );
    }

    private function freeze(): void
    {
        $clock = new MockClock(self::FROZEN_AT);
        (new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $clock),
            $this->typeNameResolverOfSdeOres(),
            $this->createStub(EntityManagerInterface::class),
            $clock,
        ))->freeze($this->operation);
    }

    private function refusalOf(callable $action): ?array
    {
        try {
            $action();
        } catch (HttpException $refusal) {
            return [$refusal->getStatusCode(), $refusal->getMessage()];
        }

        return null;
    }

    private function fleetStorageToArray(MiningFleetStorageResource $fleetStorage): array
    {
        return [
            'operationId' => $fleetStorage->operationId,
            'available' => $fleetStorage->available,
            'editable' => $fleetStorage->editable,
            'recipientMemberId' => $fleetStorage->recipientMemberId,
            'recipientName' => $fleetStorage->recipientName,
            'containerName' => $fleetStorage->containerName,
            'declarations' => $fleetStorage->declarations,
        ];
    }

    private function canDeclareOf(MiningFleetStorageResource $fleetStorage): array
    {
        return array_combine(
            array_column($fleetStorage->declarations, 'characterName'),
            array_column($fleetStorage->declarations, 'canDeclare'),
        );
    }
}
