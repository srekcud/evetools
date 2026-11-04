<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\Account\AccountDeletionService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-267')]
final class AccountDeletionMiningSiteTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    public function testDeletedAccountTakesItsMiningSitesAndTheirScansWithItWhileTheOtherAccountKeepsItsOwn(): void
    {
        $aria = $this->createUser();
        $brann = $this->createUser();
        $ariaBelt = $this->persistSite($aria, 'Belt Aldranette');
        $ariaAnomaly = $this->persistSite($aria, 'Anomalie Spodumain');
        $brannBelt = $this->persistSite($brann, 'Belt Jaspet');
        $this->persistScan($ariaBelt, '2026-11-05 20:00:00', 30_000);
        $this->persistScan($ariaBelt, '2026-11-05 20:10:00', 25_000);
        $this->persistScan($ariaAnomaly, '2026-11-05 21:00:00', 12_000);
        $this->persistScan($brannBelt, '2026-11-05 20:00:00', 45_000);
        $this->persistScan($brannBelt, '2026-11-05 20:15:00', 40_000);
        $this->flushAndClear();
        $ariaId = (string) $aria->getId();
        $brannId = (string) $brann->getId();
        $ariaSiteIds = [(string) $ariaBelt->getId(), (string) $ariaAnomaly->getId()];
        $brannSiteIds = [(string) $brannBelt->getId()];

        $deletedAccount = $this->em->find(User::class, $ariaId);
        \assert($deletedAccount instanceof User);
        self::getContainer()->get(AccountDeletionService::class)->delete($deletedAccount);

        self::assertSame(
            ['sites of the deleted account' => 0, 'scans of the deleted account' => 0, 'sites of the other account' => 1, 'scans of the other account' => 2],
            [
                'sites of the deleted account' => $this->countWhereIn('mining_sites', 'owner_id', [$ariaId]),
                'scans of the deleted account' => $this->countWhereIn('mining_site_scans', 'site_id', $ariaSiteIds),
                'sites of the other account' => $this->countWhereIn('mining_sites', 'owner_id', [$brannId]),
                'scans of the other account' => $this->countWhereIn('mining_site_scans', 'site_id', $brannSiteIds),
            ],
        );
    }

    #[Group('issue-306')]
    public function testDeletedOperationLeavesItsSitesToTheirOwnersUnlinked(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, withToken: false);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, withToken: false);
        $spodumain = $this->persistMiningOperation($this->em, $aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($spodumain, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $brannBelt = $this->persistSite($brann, 'Belt Jaspet', $spodumain);
        $this->persistScan($brannBelt, '2026-11-05T20:00:00', 45_000, $aria);
        $this->flushAndClear();
        $spodumainId = (string) $spodumain->getId();
        $brannBeltId = (string) $brannBelt->getId();

        $this->deleteAccountOf((string) $aria->getId());

        self::assertSame(
            ['operation' => 0, 'site kept' => 1, 'site still linked' => 0, 'scans kept' => 1],
            [
                'operation' => $this->countWhereIn('mining_operations', 'id', [$spodumainId]),
                'site kept' => $this->countWhereIn('mining_sites', 'id', [$brannBeltId]),
                'site still linked' => $this->countWhereIn('mining_sites', 'operation_id', [$spodumainId]),
                'scans kept' => $this->countWhereIn('mining_site_scans', 'site_id', [$brannBeltId]),
            ],
        );
    }

    #[Group('issue-306')]
    public function testDeletedAuthorLeavesHisScansOnTheSiteOfAnotherParticipantWithAnUnknownAuthor(): void
    {
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, withToken: false);
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1, withToken: false);
        $spodumain = $this->persistMiningOperation($this->em, $brann, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Brann Holt']);
        $this->addMiningMembership($spodumain, $cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $brannBelt = $this->persistSite($brann, 'Belt Jaspet', $spodumain);
        $this->persistScan($brannBelt, '2026-11-05T20:00:00', 45_000, $brann);
        $this->persistScan($brannBelt, '2026-11-05T20:10:00', 40_000, $cyra);
        $this->flushAndClear();
        $brannId = (string) $brann->getId();
        $brannBeltId = (string) $brannBelt->getId();
        $spodumainId = (string) $spodumain->getId();

        $this->deleteAccountOf((string) $cyra->getId());

        self::assertSame(
            ['site kept' => 1, 'site still linked' => 1, 'scans kept' => 2, 'scans of the site owner' => 1, 'scans of an unknown author' => 1],
            [
                'site kept' => $this->countWhereIn('mining_sites', 'id', [$brannBeltId]),
                'site still linked' => $this->countWhereIn('mining_sites', 'operation_id', [$spodumainId]),
                'scans kept' => $this->countWhereIn('mining_site_scans', 'site_id', [$brannBeltId]),
                'scans of the site owner' => $this->countWhereIn('mining_site_scans', 'author_id', [$brannId]),
                'scans of an unknown author' => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_site_scans WHERE site_id::text = ? AND author_id IS NULL', [$brannBeltId]),
            ],
        );
    }

    #[Group('issue-306')]
    public function testDeletedSiteOwnerTakesTheSiteAndAllItsScansEvenThoseOfOtherAuthors(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, withToken: false);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, withToken: false);
        $spodumain = $this->persistMiningOperation($this->em, $aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($spodumain, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $ariaBelt = $this->persistSite($aria, 'Belt Aldranette', $spodumain);
        $brannBelt = $this->persistSite($brann, 'Belt Jaspet');
        $this->persistScan($ariaBelt, '2026-11-05T20:00:00', 30_000, $aria);
        $this->persistScan($ariaBelt, '2026-11-05T20:10:00', 25_000, $brann);
        $this->persistScan($brannBelt, '2026-11-05T20:00:00', 45_000, $brann);
        $this->flushAndClear();
        $ariaBeltId = (string) $ariaBelt->getId();
        $brannBeltId = (string) $brannBelt->getId();

        $this->deleteAccountOf((string) $aria->getId());

        self::assertSame(
            ['site of the deleted owner' => 0, 'scans of that site' => 0, 'site of the other account' => 1, 'scans of the other account site' => 1],
            [
                'site of the deleted owner' => $this->countWhereIn('mining_sites', 'id', [$ariaBeltId]),
                'scans of that site' => $this->countWhereIn('mining_site_scans', 'site_id', [$ariaBeltId]),
                'site of the other account' => $this->countWhereIn('mining_sites', 'id', [$brannBeltId]),
                'scans of the other account site' => $this->countWhereIn('mining_site_scans', 'site_id', [$brannBeltId]),
            ],
        );
    }

    private function deleteAccountOf(string $userId): void
    {
        $account = $this->em->find(User::class, $userId);
        \assert($account instanceof User);
        self::getContainer()->get(AccountDeletionService::class)->delete($account);
    }

    private function persistSite(User $owner, string $label, ?MiningOperation $operation = null): MiningSite
    {
        $site = (new MiningSite())->setOwner($owner)->setOperation($operation)->setLabel($label)->setSolarSystemId(30_003_504);
        $this->em->persist($site);

        return $site;
    }

    private function persistScan(MiningSite $site, string $scannedAt, int $units, ?User $author = null): void
    {
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($author)
            ->setScannedAt(new \DateTimeImmutable($scannedAt))
            ->setTotalUnits($units)
            ->setRemainingValue($units * 15.0));
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
