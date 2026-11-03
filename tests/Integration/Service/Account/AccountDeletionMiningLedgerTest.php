<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Account\AccountDeletionService;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReading;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-263')]
final class AccountDeletionMiningLedgerTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    private const int SPODUMAIN = 19;
    private const int S1 = 30_003_504;

    private User $aria;
    private User $brann;
    private User $cyra;
    private MiningOperation $spodumain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II'], withToken: false);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II'], withToken: false);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2, [], withToken: false);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $brannMembership = $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $cyraMembership = $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->spodumain->start(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'), null);
        foreach ([...$this->spodumain->membershipOf($this->aria)->getCharacters(), ...$brannMembership->getCharacters(), ...$cyraMembership->getCharacters()] as $enrolment) {
            $this->takeBothSnapshotsOf($enrolment);
        }
        $this->spodumain->end(new \DateTimeImmutable('2026-11-05T21:30:00+00:00'));
        $this->enrolmentOf('Brann II')->correct(self::SPODUMAIN, 35_500, 20_000, $this->cyra, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $this->enrolmentOf('Aria Solberg')->correct(self::SPODUMAIN, 35_500, 30_000, $this->brann, new \DateTimeImmutable('2026-11-05T21:52:00+00:00'));
        $this->flushAndClear();
    }

    public function testDeletedParticipantTakesTheSnapshotsAndCorrectionsOfHisCharactersWithHim(): void
    {
        $operationId = (string) $this->spodumain->getId();

        $this->deleteAccountOf($this->brann);

        self::assertSame(
            ['snapshots left' => 4, 'snapshot lines left' => 2, 'corrections left' => 1],
            [
                'snapshots left' => $this->countSnapshotsOf($operationId),
                'snapshot lines left' => (int) $this->em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM mining_ledger_snapshot_lines l JOIN mining_ledger_snapshots s ON s.id = l.snapshot_id JOIN mining_operation_characters c ON c.id = s.operation_character_id JOIN mining_operation_members m ON m.id = c.member_id WHERE m.operation_id::text = ?',
                    [$operationId],
                ),
                'corrections left' => $this->countWhereIn('mining_quantity_corrections', 'type_id', [(string) self::SPODUMAIN]),
            ],
        );
    }

    public function testCorrectionOfADeletedAuthorKeepsItsAuthorName(): void
    {
        $this->deleteAccountOf($this->cyra);

        self::assertSame(
            [null, 'Cyra Lune', 20_000],
            array_values((array) $this->em->getConnection()->fetchAssociative(
                'SELECT corrected_by_id, corrected_by_name, corrected_quantity FROM mining_quantity_corrections WHERE corrected_quantity = 20000',
            )),
        );
    }

    private function takeBothSnapshotsOf(MiningOperationCharacter $enrolment): void
    {
        $start = $enrolment->scheduleStartSnapshot(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        $start->take(new \DateTimeImmutable('2026-11-05T20:00:02+00:00'), new MiningLedgerReading([new MiningLedgerEntry(new \DateTimeImmutable('2026-11-05'), self::SPODUMAIN, self::S1, 12_000)], null));
        $end = $enrolment->scheduleEndSnapshot(new \DateTimeImmutable('2026-11-05T21:30:00+00:00'));
        $end->schedule(new \DateTimeImmutable('2026-11-05T21:37:17+00:00'));
        $end->take(new \DateTimeImmutable('2026-11-05T21:37:17+00:00'), new MiningLedgerReading([], null));
    }

    private function enrolmentOf(string $characterName): MiningOperationCharacter
    {
        foreach ($this->spodumain->getMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                if ($enrolment->getCharacter()->getName() === $characterName) {
                    return $enrolment;
                }
            }
        }

        throw new \LogicException(sprintf('%s is not enrolled', $characterName));
    }

    private function deleteAccountOf(User $user): void
    {
        $deletedAccount = $this->em->find(User::class, (string) $user->getId());
        \assert($deletedAccount instanceof User);
        self::getContainer()->get(AccountDeletionService::class)->delete($deletedAccount);
    }

    private function countSnapshotsOf(string $operationId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mining_ledger_snapshots s JOIN mining_operation_characters c ON c.id = s.operation_character_id JOIN mining_operation_members m ON m.id = c.member_id WHERE m.operation_id::text = ?',
            [$operationId],
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
