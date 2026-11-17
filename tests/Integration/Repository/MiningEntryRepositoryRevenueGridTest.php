<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1066')]
final class MiningEntryRepositoryRevenueGridTest extends IntegrationTestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int MAIN = 93000001;
    private const int ALT = 93000002;

    private MiningEntryRepository $repository;
    private \DateTimeImmutable $to;
    private \DateTimeImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MiningEntryRepository::class);
        $this->to = new \DateTimeImmutable('2026-11-10 12:00:00');
        $this->from = $this->to->modify('-30 days');
    }

    public function testQuantitiesAreSummedPerUsageDayOreAndCharacter(): void
    {
        $pilot = $this->createUser();
        $this->entry($pilot, '2026-11-07', self::VELDSPAR, self::MAIN, 'Main', 100, 'sold');
        $this->entry($pilot, '2026-11-07', self::VELDSPAR, self::MAIN, 'Main', 50, 'sold', 30000142);
        $this->entry($pilot, '2026-11-07', self::VELDSPAR, self::ALT, 'Alt', 25, 'sold');
        $this->entry($pilot, '2026-11-08', self::SCORDITE, self::MAIN, 'Main', 10, 'corp_project');
        $this->flushAndClear();

        $rows = $this->repository->getQuantitiesByUsageDayTypeAndCharacter($this->em->find(User::class, $pilot->getId()), $this->from, $this->to, ['sold', 'corp_project', 'unknown']);

        usort($rows, static fn (array $a, array $b): int => [$a['day'], $a['characterId']] <=> [$b['day'], $b['characterId']]);
        self::assertSame([
            ['usage' => 'sold', 'day' => '2026-11-07', 'typeId' => self::VELDSPAR, 'characterId' => self::MAIN, 'characterName' => 'Main', 'quantity' => 150],
            ['usage' => 'sold', 'day' => '2026-11-07', 'typeId' => self::VELDSPAR, 'characterId' => self::ALT, 'characterName' => 'Alt', 'quantity' => 25],
            ['usage' => 'corp_project', 'day' => '2026-11-08', 'typeId' => self::SCORDITE, 'characterId' => self::MAIN, 'characterName' => 'Main', 'quantity' => 10],
        ], $rows);
    }

    public function testOnlyTheRequestedUsagesTheRequestedUserAndTheRequestedPeriodAreRead(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->entry($pilot, '2026-11-07', self::VELDSPAR, self::MAIN, 'Main', 100, 'sold');
        $this->entry($pilot, '2026-11-07', self::VELDSPAR, self::MAIN, 'Main', 200, 'industry', 30000142);
        $this->entry($pilot, '2026-09-01', self::VELDSPAR, self::MAIN, 'Main', 300, 'sold');
        $this->entry($otherPilot, '2026-11-07', self::VELDSPAR, self::MAIN, 'Main', 400, 'sold');
        $this->flushAndClear();

        $rows = $this->repository->getQuantitiesByUsageDayTypeAndCharacter($this->em->find(User::class, $pilot->getId()), $this->from, $this->to, ['sold', 'corp_project', 'unknown']);

        self::assertCount(1, $rows);
        self::assertSame(100, $rows[0]['quantity']);
    }

    private function entry(User $user, string $date, int $typeId, int $characterId, string $characterName, int $quantity, string $usage, int $solarSystemId = 30003504): void
    {
        $entry = (new MiningEntry())
            ->setUser($user)
            ->setCharacterId($characterId)
            ->setCharacterName($characterName)
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName('Ore')
            ->setSolarSystemId($solarSystemId)
            ->setSolarSystemName('System')
            ->setQuantity($quantity)
            ->setUsage($usage);
        $this->em->persist($entry);
    }
}
