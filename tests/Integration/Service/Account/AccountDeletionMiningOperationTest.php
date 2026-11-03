<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningFrozenUnitPrice;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningPaymentKind;
use App\Repository\MiningOperationRepository;
use App\Service\Account\AccountDeletionService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-262')]
final class AccountDeletionMiningOperationTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    public function testDeletedOwnerTakesHisOperationsAndMembershipsWithHimWhileTheOtherAccountKeepsItsOwn(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II'], withToken: false);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II'], withToken: false);
        $spodumain = $this->persistMiningOperation($this->em, $aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg', 'Aria II']);
        $this->addMiningMembership($spodumain, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $iceBelt = $this->persistMiningOperation($this->em, $brann, 'Ice belt — dimanche', '2026-11-08T18:00:00+00:00', ownerCharacterNames: ['Brann II']);
        $this->addMiningMembership($iceBelt, $aria, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $this->flushAndClear();
        $ariaId = (string) $aria->getId();
        $spodumainId = (string) $spodumain->getId();
        $iceBeltId = (string) $iceBelt->getId();
        $brannHoltId = (string) $this->miningCharacters['Brann Holt']->getId();
        $brannIIId = (string) $this->miningCharacters['Brann II']->getId();

        $deletedAccount = $this->em->find(User::class, $ariaId);
        \assert($deletedAccount instanceof User);
        self::getContainer()->get(AccountDeletionService::class)->delete($deletedAccount);

        self::assertSame(
            [
                'operations owned' => 0,
                'memberships' => 0,
                'deleted operation' => 0,
                'enrolment in the deleted operation' => 0,
                'kept operation' => 1,
                'members of the kept operation' => 1,
                'enrolment in the kept operation' => 1,
            ],
            [
                'operations owned' => $this->countWhereIn('mining_operations', 'owner_id', [$ariaId]),
                'memberships' => $this->countWhereIn('mining_operation_members', 'user_id', [$ariaId]),
                'deleted operation' => $this->countWhereIn('mining_operations', 'id', [$spodumainId]),
                'enrolment in the deleted operation' => $this->countWhereIn('mining_operation_characters', 'character_id', [$brannHoltId]),
                'kept operation' => $this->countWhereIn('mining_operations', 'id', [$iceBeltId]),
                'members of the kept operation' => $this->countWhereIn('mining_operation_members', 'operation_id', [$iceBeltId]),
                'enrolment in the kept operation' => $this->countWhereIn('mining_operation_characters', 'character_id', [$brannIIId]),
            ],
        );
    }

    #[Group('issue-265')]
    public function testOperationInItsSharesGoesToTheOldestAcceptedMemberAndKeepsTheFrozenNameOfTheDeletedOwner(): void
    {
        $operationId = $this->g1OperationOfAria();

        $this->deleteAccountOf('Aria Solberg');

        self::assertSame(
            [
                'operation' => 1,
                'owner' => 'Brann Holt',
                'owner membership' => ['Brann Holt' => 'owner', 'Cyra Lune' => 'member'],
                'frozen values' => [[0, null, 'Aria Solberg', 2_699_093.0], [1, 'Brann Holt', 'Brann Holt', 2_868_460.0], [2, 'Cyra Lune', 'Cyra Lune', 176.0]],
            ],
            [
                'operation' => $this->countWhereIn('mining_operations', 'id', [$operationId]),
                'owner' => $this->ownerNameOf($operationId),
                'owner membership' => $this->rolesByMainCharacterName($operationId),
                'frozen values' => $this->frozenValuesOf($operationId),
            ],
        );
    }

    #[Group('issue-265')]
    public function testPaymentPaidByTheDeletedOwnerKeepsHisNamesWithoutMemberNorAuthor(): void
    {
        $operationId = $this->g1OperationOfAria();

        $this->deleteAccountOf('Aria Solberg');

        self::assertSame(
            [
                [0, null, 'Aria Solberg', 'Cyra Lune', null, 'Aria Solberg', 404_864.0],
                [1, 'Brann Holt', 'Brann Holt', 'Cyra Lune', null, null, 430_269.0],
                [2, 'Cyra Lune', 'Cyra Lune', null, null, null, 278_386.0],
            ],
            $this->paymentRowsOf($operationId),
        );
    }

    #[Group('issue-265')]
    public function testOperationInItsSharesGoesToTheAcceptedAdminFirst(): void
    {
        $operationId = $this->g1OperationOfAria(cyraRole: MiningOperationMemberRole::Admin);

        $this->deleteAccountOf('Aria Solberg');

        self::assertSame(
            ['Cyra Lune', ['Brann Holt' => 'member', 'Cyra Lune' => 'owner']],
            [$this->ownerNameOf($operationId), $this->rolesByMainCharacterName($operationId)],
        );
    }

    #[Group('issue-265')]
    public function testSettledOperationOfTheDeletedOwnerStaysInTheArchiveOfTheOtherParticipants(): void
    {
        $operationId = $this->g1OperationOfAria(status: MiningOperationStatus::Settled);

        $this->deleteAccountOf('Aria Solberg');

        $repository = self::getContainer()->get(MiningOperationRepository::class);
        $archiveOf = fn (string $mainCharacterName): array => array_map(
            static fn (MiningOperation $operation): string => (string) $operation->getId(),
            $repository->findArchivedReadableBy($this->userNamed($mainCharacterName)),
        );
        self::assertSame(
            ['Brann Holt' => [$operationId], 'Cyra Lune' => [$operationId]],
            ['Brann Holt' => $archiveOf('Brann Holt'), 'Cyra Lune' => $archiveOf('Cyra Lune')],
        );
    }

    #[Group('issue-265')]
    public function testClosingOperationWithoutAnyOtherAcceptedMemberIsDeletedWithItsOwner(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, withToken: false);
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2, withToken: false);
        $operation = $this->persistMiningOperation($this->em, $aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', status: MiningOperationStatus::Closing, ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($operation, $cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $this->flushAndClear();
        $operationId = (string) $operation->getId();

        $this->deleteAccountOf('Aria Solberg');

        self::assertSame(0, $this->countWhereIn('mining_operations', 'id', [$operationId]));
    }

    private function g1OperationOfAria(MiningOperationStatus $status = MiningOperationStatus::Shares, MiningOperationMemberRole $cyraRole = MiningOperationMemberRole::Member): string
    {
        $ariaUser = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, withToken: false);
        $brannUser = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, withToken: false);
        $cyraUser = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2, withToken: false);
        $operation = $this->persistMiningOperation($this->em, $ariaUser, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $aria = $operation->membershipOf($ariaUser);
        \assert($aria instanceof MiningOperationMember);
        $brann = $this->addMiningMembership($operation, $brannUser, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $cyra = $this->addMiningMembership($operation, $cyraUser, $cyraRole, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->joinedAt($brann, '2026-11-04T08:15:00+00:00');
        $this->joinedAt($cyra, '2026-11-04T09:30:00+00:00');
        $operation->updateValuationSettings(boosterCutPercent: 10, corporationCutPercent: 5, boosterMember: $cyra);
        $operation->freezeShares(
            new \DateTimeImmutable('2026-11-05T21:52:00+00:00'),
            new \DateTimeImmutable('2026-11-05T21:00:00+00:00'),
            [new MiningFrozenUnitPrice($operation, 1230, 19.61), new MiningFrozenUnitPrice($operation, 45492, 221.45)],
            [
                new MiningFrozenMinedValue($operation, $aria, 'Aria Solberg', 0, 2_699_093.0),
                new MiningFrozenMinedValue($operation, $brann, 'Brann Holt', 1, 2_868_460.0),
                new MiningFrozenMinedValue($operation, $cyra, 'Cyra Lune', 2, 176.0),
            ],
        );
        $ariaPayment = new MiningPayment($operation, 0, MiningPaymentKind::BoosterCut, $aria, 'Aria Solberg', $cyra, 'Cyra Lune', 404_864.0);
        $ariaPayment->markPaid($ariaUser, 'Aria Solberg', new \DateTimeImmutable('2026-11-05T22:24:00+00:00'));
        $operation->getPayments()->add($ariaPayment);
        $operation->getPayments()->add(new MiningPayment($operation, 1, MiningPaymentKind::BoosterCut, $brann, 'Brann Holt', $cyra, 'Cyra Lune', 430_269.0));
        $operation->getPayments()->add(new MiningPayment($operation, 2, MiningPaymentKind::CollectedCorporationCut, $cyra, 'Cyra Lune', null, null, 278_386.0));
        $operation->setStatus($status);
        $this->flushAndClear();

        return (string) $operation->getId();
    }

    private function joinedAt(MiningOperationMember $member, string $joinedAt): void
    {
        (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable($joinedAt));
    }

    private function deleteAccountOf(string $mainCharacterName): void
    {
        self::getContainer()->get(AccountDeletionService::class)->delete($this->userNamed($mainCharacterName));
        $this->em->clear();
    }

    private function userNamed(string $mainCharacterName): User
    {
        $user = $this->em->find(User::class, $this->miningCharacters[$mainCharacterName]->getUser()?->getId());
        \assert($user instanceof User);

        return $user;
    }

    private function ownerNameOf(string $operationId): ?string
    {
        $name = $this->em->getConnection()->fetchOne(
            'SELECT c.name FROM mining_operations o JOIN users u ON u.id = o.owner_id JOIN characters c ON c.id = u.main_character_id WHERE o.id::text = ?',
            [$operationId],
        );

        return $name === false ? null : (string) $name;
    }

    private function rolesByMainCharacterName(string $operationId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT c.name, m.role FROM mining_operation_members m JOIN users u ON u.id = m.user_id JOIN characters c ON c.id = u.main_character_id WHERE m.operation_id::text = ? ORDER BY c.name',
            [$operationId],
        );

        return array_column($rows, 'role', 'name');
    }

    private function frozenValuesOf(string $operationId): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['position'], $row['member_name'], $row['participant_name'], (float) $row['mined_value']],
            $this->em->getConnection()->fetchAllAssociative(
                'SELECT v.position, c.name AS member_name, v.participant_name, v.mined_value FROM mining_frozen_mined_values v LEFT JOIN mining_operation_members m ON m.id = v.member_id LEFT JOIN users u ON u.id = m.user_id LEFT JOIN characters c ON c.id = u.main_character_id WHERE v.operation_id::text = ? ORDER BY v.position',
                [$operationId],
            ),
        );
    }

    private function paymentRowsOf(string $operationId): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['position'], $row['payer_member_name'], $row['payer_name'], $row['beneficiary_name'], $row['paid_by_id'], $row['paid_by_name'], (float) $row['amount']],
            $this->em->getConnection()->fetchAllAssociative(
                'SELECT p.position, c.name AS payer_member_name, p.payer_name, p.beneficiary_name, p.paid_by_id, p.paid_by_name, p.amount FROM mining_payments p LEFT JOIN mining_operation_members m ON m.id = p.payer_member_id LEFT JOIN users u ON u.id = m.user_id LEFT JOIN characters c ON c.id = u.main_character_id WHERE p.operation_id::text = ? ORDER BY p.position',
                [$operationId],
            ),
        );
    }

    private function countWhereIn(string $table, string $column, array $ids): int
    {
        $connection = $this->em->getConnection();

        return (int) $connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s::text IN (?)', $connection->quoteSingleIdentifier($table), $connection->quoteSingleIdentifier($column)),
            [$ids],
            [ArrayParameterType::STRING],
        );
    }
}
