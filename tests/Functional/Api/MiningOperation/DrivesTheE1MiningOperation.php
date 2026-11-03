<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use App\Service\JitaMarketService;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReading;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait DrivesTheE1MiningOperation
{
    use PersistsMiningOperations;

    private const string SPODUMAIN_LABEL = 'Anomalie Spodumain — 20:00';
    private const string STARTED_AT = '2026-11-05T20:00:00+00:00';
    private const string BRANN_DEPARTED_AT = '2026-11-05T21:05:00+00:00';
    private const string ENDED_AT = '2026-11-05T21:30:00+00:00';
    private const string READ_AT = '2026-11-05T21:50:00+00:00';
    private const string FROZEN_AT = '2026-11-05T21:52:00+00:00';
    private const string JITA_SYNCED_AT = '2026-11-05T21:00:00+00:00';
    private const string CORPORATION_NAME = 'Corpo Exemple';
    private const int S1 = 30_003_504;
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int BITUMENS = 45492;
    private const int COMPRESSED_BITUMENS = 62454;
    private const int E1_TOTAL_VALUE = 5_567_729;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;
    private User $elia;
    private MiningOperation $spodumain;
    private MiningOperationMember $cyraMembership;
    private array $jitaSellPrices = [
        self::VELDSPAR => 18.37,
        self::COMPRESSED_VELDSPAR => 19.61,
        self::BITUMENS => 213.9,
        self::COMPRESSED_BITUMENS => 221.45,
    ];

    private function setUpTheE1Pilots(): void
    {
        self::mockTime(new \DateTimeImmutable(self::READ_AT));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedStaticData();
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->aria->getMainCharacter()?->setCorporationName(self::CORPORATION_NAME);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);
        $this->elia = $this->persistMiningPilot($this->em, 'Elia Varr', self::C1);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, self::SPODUMAIN_LABEL, self::STARTED_AT, ownerCharacterNames: ['Aria Solberg', 'Aria II']);
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->cyraMembership = $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->em->flush();
    }

    private function closingE1(array $unreadStarts = [], array $failedEnds = [], MiningSplitMode $splitMode = MiningSplitMode::ValueProrata): void
    {
        $operation = $this->em->find(MiningOperation::class, $this->spodumain->getId());
        \assert($operation instanceof MiningOperation);
        $this->spodumain = $operation;
        $operation->setSplitMode($splitMode);
        $startedAt = new \DateTimeImmutable(self::STARTED_AT);
        $operation->start($startedAt, $this->em->find(MapSolarSystem::class, self::S1));
        $startLedgers = [
            'Aria Solberg' => [[self::VELDSPAR, 5_000]],
            'Aria II' => [],
            'Brann Holt' => [[self::BITUMENS, 1_000]],
            'Brann II' => [],
            'Cyra Lune' => [[self::VELDSPAR, 2]],
        ];
        foreach ($startLedgers as $characterName => $rows) {
            $start = $this->enrolmentNamed($characterName)->scheduleStartSnapshot($startedAt);
            if (in_array($characterName, $unreadStarts, true)) {
                $start->fail(MiningLedgerSnapshotStatus::ReconnectRequired, 'Token revoked');
            } else {
                $start->take(new \DateTimeImmutable('2026-11-05T20:00:02+00:00'), $this->reading($rows));
            }
        }

        $brannDepartedAt = new \DateTimeImmutable(self::BRANN_DEPARTED_AT);
        $this->membershipNamed('Brann Holt')->depart($brannDepartedAt);
        $endedAt = new \DateTimeImmutable(self::ENDED_AT);
        $operation->end($endedAt);
        $endLedgers = [
            'Aria Solberg' => [$endedAt, [[self::VELDSPAR, 105_000]]],
            'Aria II' => [$endedAt, [[self::BITUMENS, 3_333]]],
            'Brann Holt' => [$brannDepartedAt, [[self::BITUMENS, 11_001]]],
            'Brann II' => [$brannDepartedAt, [[self::VELDSPAR, 33_337]]],
            'Cyra Lune' => [$endedAt, [[self::VELDSPAR, 11]]],
        ];
        foreach ($endLedgers as $characterName => [$referenceAt, $rows]) {
            if (in_array($characterName, $unreadStarts, true)) {
                continue;
            }
            $end = $this->enrolmentNamed($characterName)->scheduleEndSnapshot($referenceAt);
            $end->schedule(new \DateTimeImmutable('2026-11-05T21:42:00+00:00'));
            if (in_array($characterName, $failedEnds, true)) {
                $end->fail(MiningLedgerSnapshotStatus::Failed, 'ESI server error');
            } else {
                $end->take(new \DateTimeImmutable('2026-11-05T21:42:01+00:00'), $this->reading($rows));
            }
        }
        $this->em->flush();
    }

    private function g1(): void
    {
        $this->closingE1();
        $this->updateValuation(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'corporationCutPercent' => 5, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        self::mockTime(new \DateTimeImmutable(self::FROZEN_AT));
        $this->as($this->aria);
        $this->post('freeze-shares');
        self::assertResponseStatusCodeSame(200);
    }

    private function updateValuation(array $settings): void
    {
        $this->as($this->aria);
        $this->request('PATCH', sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId()), $settings);
        self::assertResponseStatusCodeSame(200);
    }

    private function reading(array $rows): MiningLedgerReading
    {
        return new MiningLedgerReading(
            array_map(static fn (array $row): MiningLedgerEntry => new MiningLedgerEntry(new \DateTimeImmutable('2026-11-05T00:00:00+00:00'), $row[0], self::S1, $row[1]), $rows),
            null,
        );
    }

    private function enrolmentNamed(string $characterName): MiningOperationCharacter
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

    private function membershipNamed(string $mainCharacterName): MiningOperationMember
    {
        foreach ($this->spodumain->getMembers() as $member) {
            if ($member->getUser()->getMainCharacter()?->getName() === $mainCharacterName) {
                return $member;
            }
        }

        throw new \LogicException(sprintf('%s is not a member', $mainCharacterName));
    }

    private function enrolmentIdOf(string $characterName): string
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (string) $connection->fetchOne(
            'SELECT c.id FROM mining_operation_characters c JOIN mining_operation_members m ON m.id = c.member_id WHERE m.operation_id::text = ? AND c.character_id::text = ?',
            [(string) $this->spodumain->getId(), (string) $this->miningCharacters[$characterName]->getId()],
        );
    }

    private function reloadedOperation(): MiningOperation
    {
        $operation = self::getContainer()->get(EntityManagerInterface::class)->find(MiningOperation::class, $this->spodumain->getId());
        \assert($operation instanceof MiningOperation);

        return $operation;
    }

    private function replaceMarket(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getPrices')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, $this->jitaSellPrices));
        $jitaMarket->method('getBuyPrices')->willReturnCallback(fn (array $typeIds): array => $this->pricesOf($typeIds, []));
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
        self::getContainer()->set('logger', new NullLogger());
    }

    private function pricesOf(array $typeIds, array $prices): array
    {
        return array_combine($typeIds, array_map(static fn (int $typeId): ?float => $prices[$typeId] ?? null, $typeIds));
    }

    private function as(User $user): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function seedStaticData(): void
    {
        $region = (new MapRegion())->setRegionId(10_000_032)->setRegionName('Sinq Laison');
        $constellation = (new MapConstellation())->setConstellationId(20_000_441)->setConstellationName('Ancbeu')->setRegion($region);
        $aldranette = (new MapSolarSystem())->setSolarSystemId(self::S1)->setSolarSystemName('Aldranette')->setConstellation($constellation)->setRegionId(10_000_032);
        $category = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $veldsparGroup = (new InvGroup())->setGroupId(462)->setGroupName('Veldspar')->setCategory($category);
        $moonGroup = (new InvGroup())->setGroupId(1884)->setGroupName('Ubiquitous Moon Asteroids')->setCategory($category);
        foreach ([$region, $constellation, $aldranette, $category, $veldsparGroup, $moonGroup] as $staticRow) {
            $this->em->persist($staticRow);
        }
        foreach ([
            [self::VELDSPAR, 'Veldspar', $veldsparGroup, 0.1],
            [self::COMPRESSED_VELDSPAR, 'Compressed Veldspar', $veldsparGroup, 0.001],
            [self::BITUMENS, 'Bitumens', $moonGroup, 10.0],
            [self::COMPRESSED_BITUMENS, 'Compressed Bitumens', $moonGroup, 0.1],
        ] as [$typeId, $typeName, $group, $volume]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume($volume)->setPortionSize(100)->setPublished(true));
        }
    }

    private function post(string $action, array $body = []): void
    {
        $this->request('POST', sprintf('/api/mining-operations/%s/%s', $this->spodumain->getId(), $action), $body);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function fields(array $resource, array $names): array
    {
        return array_map(static fn (string $name): mixed => $resource[$name] ?? null, $names);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_column($this->responseJson()['violations'] ?? [], 'propertyPath')));
    }
}
