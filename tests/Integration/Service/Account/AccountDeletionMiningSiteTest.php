<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Service\Account\AccountDeletionService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-267')]
final class AccountDeletionMiningSiteTest extends IntegrationTestCase
{
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

    private function persistSite(User $owner, string $label): MiningSite
    {
        $site = (new MiningSite())->setOwner($owner)->setLabel($label)->setSolarSystemId(30_003_504);
        $this->em->persist($site);

        return $site;
    }

    private function persistScan(MiningSite $site, string $scannedAt, int $units): void
    {
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
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
