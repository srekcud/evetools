<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\Entity\MiningOperation;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\CharacterEligibility;
use App\Service\MiningOperation\MiningOperationEnrolment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningOperationEnrolment::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class MiningOperationEnrolmentTest extends TestCase
{
    use BuildsSpodumainOperation;

    private EntityManagerInterface&MockObject $entityManager;
    private MiningOperationEnrolment $enrolment;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->holdInOpenOperation('Brann IV', $this->iceBeltOperation());
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->enrolment = new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->entityManager);
    }

    public function testAriaCreatesAPlannedCorporationOperationOfHerCorporationAsAcceptedOwnerWithHerTwoCharacters(): void
    {
        $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(MiningOperation::class));
        $this->entityManager->expects($this->once())->method('flush');

        $operation = $this->enrolment->create($this->aria, $this->creation(characterNames: ['Aria Solberg', 'Aria II']));

        self::assertSame(MiningOperationStatus::Planned, $operation->getStatus());
        self::assertSame(self::SPODUMAIN_LABEL, $operation->getLabel());
        self::assertSame(MiningOperationVisibility::Corporation, $operation->getVisibility());
        self::assertSame(MiningSplitMode::ValueProrata, $operation->getSplitMode());
        self::assertSame(98262101, $operation->getCorporationId());
        self::assertSame($this->aria, $operation->getOwner());
        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, $operation->getJoinCode());
        self::assertCount(1, $operation->getMembers());
        $ownerMembership = $operation->getMembers()->first();
        self::assertSame($this->aria, $ownerMembership->getUser());
        self::assertSame(MiningOperationMemberRole::Owner, $ownerMembership->getRole());
        self::assertSame(MiningOperationMemberStatus::Accepted, $ownerMembership->getStatus());
        self::assertSame(['Aria II', 'Aria Solberg'], $this->enrolledNamesOf($ownerMembership));
    }

    public function testPrivateEachForThemselvesOperationStillKeepsTheCorporationOfItsOwner(): void
    {
        $operation = $this->enrolment->create($this->aria, $this->creation(
            visibility: 'private',
            splitMode: 'each_for_themselves',
            characterNames: ['Aria II'],
        ));

        self::assertSame(MiningOperationVisibility::Private, $operation->getVisibility());
        self::assertSame(MiningSplitMode::EachForThemselves, $operation->getSplitMode());
        self::assertSame(98262101, $operation->getCorporationId());
        self::assertSame(1, $this->enrolledCharactersCountOfAcceptedMembers($operation));
    }

    public function testPlannedStartGivenWithAnOffsetIsStoredInEveTime(): void
    {
        $operation = $this->enrolment->create($this->aria, $this->creation(plannedStartAt: '2026-11-05T21:00:00+01:00'));

        self::assertSame('2026-11-05T20:00:00+00:00', $operation->getPlannedStartAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('UTC', $operation->getPlannedStartAt()->getTimezone()->getName());
    }

    public function testLabelIsStoredWithoutItsEdgeSpaces(): void
    {
        $operation = $this->enrolment->create($this->aria, $this->creation(label: '  Anomalie Spodumain — 20:00  '));

        self::assertSame(self::SPODUMAIN_LABEL, $operation->getLabel());
    }

    public function testPilotWithoutMainCharacterCannotCreateACorporationOperation(): void
    {
        $this->expectNothingStored();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Corporation visibility needs a known corporation');

        $this->enrolment->create($this->dorn, $this->creation(characterNames: ['Dorn Achar']));
    }

    public function testPilotWithoutMainCharacterCreatesAPrivateOperationWithoutCorporation(): void
    {
        $operation = $this->enrolment->create($this->dorn, $this->creation(visibility: 'private', characterNames: ['Dorn Achar']));

        self::assertCount(1, $operation->getMembers());
        self::assertNull($operation->getCorporationId());
        self::assertSame(MiningOperationMemberRole::Owner, $operation->getMembers()->first()->getRole());
        self::assertSame(['Dorn Achar'], $this->enrolledNamesOf($operation->getMembers()->first()));
    }

    public function testCharacterOfAnotherAccountIsRefusedAtCreationAndNothingIsStored(): void
    {
        $this->expectNothingStored();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(sprintf('Character %s is not one of your characters', $this->idOf('Brann II')));

        $this->enrolment->create($this->aria, $this->creation(characterNames: ['Brann II']));
    }

    public function testUnknownCharacterIdIsRefusedLikeACharacterOfAnotherAccount(): void
    {
        $unknownCharacterId = Uuid::v4()->toRfc4122();
        $input = $this->creation();
        $input->characterIds = [$unknownCharacterId];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(sprintf('Character %s is not one of your characters', $unknownCharacterId));

        $this->enrolment->create($this->aria, $input);
    }

    public function testCharacterAlreadyInAnotherOpenOperationIsRefusedAtCreationAndNothingIsStored(): void
    {
        $this->expectNothingStored();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Brann IV is already in the open operation "Ice belt — dimanche"');

        $this->enrolment->create($this->brann, $this->creation(characterNames: ['Brann Holt', 'Brann IV']));
    }

    public function testFirstRefusedCharacterInTheTickedOrderDecidesTheRefusal(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Brann III must be reconnected before joining');

        $this->enrolment->create($this->brann, $this->creation(characterNames: ['Brann III', 'Brann IV']));
    }

    public function testBrannSeesEachOfHisCharactersWithItsEligibilityMainCharacterFirstThenByName(): void
    {
        self::assertSame(
            [
                ['Brann Holt', true, 'eligible', null],
                ['Brann II', false, 'eligible', null],
                ['Brann III', false, 'reconnect_required', null],
                ['Brann IV', false, 'in_another_operation', self::ICE_BELT_LABEL],
            ],
            $this->eligibilityRowsOf($this->enrolment->eligibilityOf($this->brann)),
        );
    }

    public function testBrannFromTheCorporationOfTheOperationWouldBeAcceptedAutomatically(): void
    {
        self::assertTrue($this->enrolment->autoAccepts($this->brann, $this->spodumainOperation()));
    }

    public function testCyraFromAnotherCorporationIsNotAcceptedAutomaticallyAndHerMainCharacterIsEligible(): void
    {
        self::assertFalse($this->enrolment->autoAccepts($this->cyra, $this->spodumainOperation()));
        self::assertSame([['Cyra Lune', true, 'eligible', null]], $this->eligibilityRowsOf($this->enrolment->eligibilityOf($this->cyra)));
    }

    public function testCharacterToReconnectAndHeldElsewhereIsShownAsToReconnect(): void
    {
        $this->character('Brann IV')->markAuthInvalid();

        self::assertSame(
            ['Brann IV', false, 'reconnect_required', null],
            $this->eligibilityRowOf('Brann IV', $this->enrolment->eligibilityOf($this->brann)),
        );
    }

    public function testCharacterWithoutTokenMustBeReconnected(): void
    {
        $this->character('Brann II')->setEveToken(null);

        self::assertSame(
            ['Brann II', false, 'reconnect_required', null],
            $this->eligibilityRowOf('Brann II', $this->enrolment->eligibilityOf($this->brann)),
        );
    }

    public function testCharacterWhoseTokenLacksTheMiningLedgerScopeMustBeReconnected(): void
    {
        $this->character('Brann II')->getEveToken()->setScopes([self::WALLET_SCOPE]);

        self::assertSame(
            ['Brann II', false, 'reconnect_required', null],
            $this->eligibilityRowOf('Brann II', $this->enrolment->eligibilityOf($this->brann)),
        );
    }

    public function testCharacterAlreadyEnrolledInTheConsultedOperationIsShownInThatOperation(): void
    {
        $operation = $this->spodumainOperation();
        $this->holdInOpenOperation('Brann II', $operation);

        self::assertSame(
            ['Brann II', false, 'in_another_operation', self::SPODUMAIN_LABEL],
            $this->eligibilityRowOf('Brann II', $this->enrolment->eligibilityOf($this->brann)),
        );
    }

    public function testBrannFromTheCorporationJoinsAsAcceptedMemberWithTwoCharacters(): void
    {
        $operation = $this->spodumainOperation();
        $this->entityManager->expects($this->once())->method('flush');

        $member = $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann Holt', 'Brann II']));

        self::assertSame($member, $this->membershipOf($operation, $this->brann));
        self::assertSame(MiningOperationMemberRole::Member, $member->getRole());
        self::assertSame(MiningOperationMemberStatus::Accepted, $member->getStatus());
        self::assertSame(['Brann Holt', 'Brann II'], $this->enrolledNamesOf($member));
        self::assertCount(2, $this->acceptedMembersOf($operation));
        self::assertSame(4, $this->enrolledCharactersCountOfAcceptedMembers($operation));
    }

    public function testCyraFromAnotherCorporationJoinsAsPendingAndIsNotYetAParticipant(): void
    {
        $operation = $this->spodumainOperation();

        $member = $this->enrolment->join($this->cyra, $operation, $this->idsOf(['Cyra Lune']));

        self::assertSame($member, $this->membershipOf($operation, $this->cyra));
        self::assertSame(MiningOperationMemberStatus::Pending, $member->getStatus());
        self::assertSame(MiningOperationMemberRole::Member, $member->getRole());
        self::assertSame(['Cyra Lune'], $this->enrolledNamesOf($member));
        self::assertCount(1, $this->acceptedMembersOf($operation));
        self::assertSame(2, $this->enrolledCharactersCountOfAcceptedMembers($operation));
    }

    public function testCorporationMemberJoiningAPrivateOperationByItsLinkIsAccepted(): void
    {
        $operation = $this->spodumainOperation(visibility: MiningOperationVisibility::Private);

        $member = $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann Holt']));

        self::assertSame(MiningOperationMemberStatus::Accepted, $member->getStatus());
    }

    public function testPilotWithoutKnownCorporationJoinsAsPending(): void
    {
        $operation = $this->spodumainOperation();

        $member = $this->enrolment->join($this->dorn, $operation, $this->idsOf(['Dorn Achar']));

        self::assertSame($member, $this->membershipOf($operation, $this->dorn));
        self::assertSame(MiningOperationMemberStatus::Pending, $member->getStatus());
    }

    public function testCharacterToReconnectIsRefusedAndNoMembershipIsCreated(): void
    {
        $operation = $this->spodumainOperation();
        $this->expectNothingStored();

        try {
            $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann III']));
            self::fail('Joining with a character to reconnect must be refused');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Brann III must be reconnected before joining', $refusal->getMessage());
        }

        self::assertNull($this->membershipOf($operation, $this->brann));
        self::assertCount(1, $operation->getMembers());
    }

    public function testCharacterHeldInAnotherOpenOperationIsRefusedAndNoneOfTheTickedCharactersIsEnrolled(): void
    {
        $operation = $this->spodumainOperation();
        $this->expectNothingStored();

        try {
            $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann Holt', 'Brann IV']));
            self::fail('Joining with a character held in another open operation must be refused');
        } catch (ConflictHttpException $refusal) {
            self::assertSame('Brann IV is already in the open operation "Ice belt — dimanche"', $refusal->getMessage());
        }

        self::assertNull($this->membershipOf($operation, $this->brann));
        self::assertCount(1, $operation->getMembers());
    }

    public function testCharacterOfAnotherAccountIsRefusedOnJoin(): void
    {
        $this->expectNothingStored();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(sprintf('Character %s is not one of your characters', $this->idOf('Aria II')));

        $this->enrolment->join($this->brann, $this->spodumainOperation(), $this->idsOf(['Aria II']));
    }

    public function testOwnerJoiningHerOwnOperationIsAlreadyAMember(): void
    {
        $this->expectNothingStored();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('You are already a member of this operation');

        $this->enrolment->join($this->aria, $this->spodumainOperation(), $this->idsOf(['Aria II']));
    }

    public function testAcceptedMemberJoiningAgainIsAlreadyAMember(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->expectNothingStored();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('You are already a member of this operation');

        $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann II']));
    }

    public function testPendingMemberJoiningAgainIsAlreadyAMember(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('You are already a member of this operation');

        $this->enrolment->join($this->cyra, $operation, $this->idsOf(['Cyra Lune']));
    }

    public function testExistingMembershipIsRefusedBeforeTheTickedCharactersAreChecked(): void
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('You are already a member of this operation');

        $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann III']));
    }

    public function testClosingOperationNoLongerAcceptsParticipants(): void
    {
        $this->expectNothingStored();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('This operation is no longer accepting participants');

        $this->enrolment->join($this->brann, $this->spodumainOperation(MiningOperationStatus::Closing), $this->idsOf(['Brann Holt']));
    }

    public function testCompletedOperationNoLongerAcceptsParticipants(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('This operation is no longer accepting participants');

        $this->enrolment->join($this->brann, $this->spodumainOperation(MiningOperationStatus::Completed), $this->idsOf(['Brann Holt']));
    }

    public function testLateArrivalJoinsAnOperationInProgressAsAccepted(): void
    {
        $member = $this->enrolment->join($this->brann, $this->spodumainOperation(MiningOperationStatus::InProgress), $this->idsOf(['Brann Holt']));

        self::assertSame(MiningOperationMemberStatus::Accepted, $member->getStatus());
    }

    public function testClosedOperationIsRefusedBeforeTheTickedCharactersAreChecked(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('This operation is no longer accepting participants');

        $this->enrolment->join($this->brann, $this->spodumainOperation(MiningOperationStatus::Closing), $this->idsOf(['Brann III']));
    }

    public function testRemovedMemberFromTheCorporationRejoinsAsPendingPlainMemberOnHisMembership(): void
    {
        $operation = $this->spodumainOperation();
        $removedMembership = $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Removed, []);
        $this->entityManager->expects($this->once())->method('flush');

        $member = $this->enrolment->join($this->brann, $operation, $this->idsOf(['Brann Holt']));

        self::assertSame($removedMembership, $member);
        self::assertCount(2, $operation->getMembers());
        self::assertSame(MiningOperationMemberStatus::Pending, $member->getStatus());
        self::assertSame(MiningOperationMemberRole::Member, $member->getRole());
        self::assertSame(['Brann Holt'], $this->enrolledNamesOf($member));
    }

    public function testCharacterHeldByAPendingRequestInAnotherOperationIsRefused(): void
    {
        $this->holdInOpenOperation('Brann II', $this->operationOwnedBy($this->elia, self::OCHRE_BELT_LABEL, self::C1, '5d8b1e3f7a'));

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Brann II is already in the open operation "Ochre belt — samedi"');

        $this->enrolment->join($this->brann, $this->spodumainOperation(), $this->idsOf(['Brann II']));
    }

    public function testCharacterFreedByARemovalFromAnotherOperationJoins(): void
    {
        $member = $this->enrolment->join($this->brann, $this->spodumainOperation(), $this->idsOf(['Brann II']));

        self::assertSame(MiningOperationMemberStatus::Accepted, $member->getStatus());
        self::assertSame(['Brann II'], $this->enrolledNamesOf($member));
    }

    private function expectNothingStored(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
    }

    private function creation(
        string $label = self::SPODUMAIN_LABEL,
        string $plannedStartAt = self::PLANNED_START,
        string $visibility = 'corporation',
        string $splitMode = 'value_prorata',
        array $characterNames = ['Aria Solberg', 'Aria II'],
    ): CreateMiningOperationInput {
        $input = new CreateMiningOperationInput();
        $input->label = $label;
        $input->plannedStartAt = new \DateTimeImmutable($plannedStartAt);
        $input->visibility = $visibility;
        $input->splitMode = $splitMode;
        $input->characterIds = $this->idsOf($characterNames);

        return $input;
    }

    private function eligibilityRowsOf(array $eligibilities): array
    {
        return array_map(static fn (CharacterEligibility $eligibility): array => [
            $eligibility->character->getName(),
            $eligibility->character->isMain(),
            $eligibility->eligibility->value,
            $eligibility->busyOperationLabel,
        ], $eligibilities);
    }

    private function eligibilityRowOf(string $characterName, array $eligibilities): ?array
    {
        foreach ($this->eligibilityRowsOf($eligibilities) as $row) {
            if ($row[0] === $characterName) {
                return $row;
            }
        }

        return null;
    }
}
