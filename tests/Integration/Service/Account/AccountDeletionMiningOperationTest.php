<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
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
