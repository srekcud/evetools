<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\CachedCharacterStanding;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CachedCharacterStandingRepository;
use App\Service\ESI\CharacterStandingsSynchronizer;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterStandingsSynchronizer::class)]
#[Group('issue-347')]
final class CharacterStandingsSynchronizerTest extends TestCase
{
    private const string STANDINGS_ENDPOINT = '/characters/2112000347/standings/';
    private const string PREVIOUS_SYNC = '2026-11-03T09:30:00+00:00';

    private Character $character;
    private EveToken $token;

    private array $storedStandings = [];

    private array $esiEndpointCalls = [];

    protected function setUp(): void
    {
        $this->character = (new Character())
            ->setName('Standings Pilot')
            ->setEveCharacterId(2_112_000_347)
            ->setStandingsSyncedAt(new \DateTimeImmutable(self::PREVIOUS_SYNC));
        $this->token = new EveToken();
        $this->storedStandings = [
            $this->standingLine(1_000_035, 'npc_corp', 2.0),
            $this->standingLine(500_001, 'faction', 1.5),
            $this->standingLine(1_000_999, 'npc_corp', -3.0),
        ];
    }

    public function testReplacesTheStoredStandingsWithTheListReadFromEsi(): void
    {
        $this->synchronize([
            ['from_id' => 1_000_035, 'from_type' => 'npc_corp', 'standing' => 4.5],
            ['from_id' => 500_002, 'from_type' => 'faction', 'standing' => -1.0],
        ]);

        $this->assertSame(
            [[500_002, 'faction', -1.0], [1_000_035, 'npc_corp', 4.5]],
            $this->storedStandingRows(),
        );
        $this->assertSame([[self::STANDINGS_ENDPOINT, $this->token]], $this->esiEndpointCalls);
    }

    public function testStampsTheSynchronizedStandingsWithTheSyncTime(): void
    {
        $syncStartedAt = new \DateTimeImmutable();

        $this->synchronize([
            ['from_id' => 500_002, 'from_type' => 'faction', 'standing' => -1.0],
        ]);

        $this->assertSame(
            [true, true],
            [
                $this->character->getStandingsSyncedAt() >= $syncStartedAt,
                $this->storedStandings[0]->getCachedAt() >= $syncStartedAt,
            ],
        );
    }

    public function testRemovesEveryStoredStandingWhenEsiListsNone(): void
    {
        $syncStartedAt = new \DateTimeImmutable();

        $this->synchronize([]);

        $this->assertSame([[], true], [$this->storedStandings, $this->character->getStandingsSyncedAt() >= $syncStartedAt]);
    }

    #[DataProvider('invalidEsiStandingsResponses')]
    public function testRefusesAnInvalidEsiResponseAndKeepsTheStoredStandings(array $esiResponse): void
    {
        $rowsBefore = $this->storedStandingRows();
        $thrown = false;

        try {
            $this->synchronize($esiResponse);
        } catch (\UnexpectedValueException) {
            $thrown = true;
        }

        $this->assertSame(
            [true, $rowsBefore, self::PREVIOUS_SYNC],
            [$thrown, $this->storedStandingRows(), $this->character->getStandingsSyncedAt()?->format(DATE_ATOM)],
        );
    }

    public static function invalidEsiStandingsResponses(): iterable
    {
        $valid = ['from_id' => 500_002, 'from_type' => 'faction', 'standing' => 1.25];

        yield 'response keyed by id instead of a list' => [[500_002 => $valid]];
        yield 'response with an error object' => [['error' => 'Forbidden']];
        yield 'entry is not an array' => [[$valid, 'faction']];
        yield 'entry without from_id' => [[$valid, ['from_type' => 'npc_corp', 'standing' => 2.5]]];
        yield 'from_id as string' => [[$valid, ['from_id' => '1000035', 'from_type' => 'npc_corp', 'standing' => 2.5]]];
        yield 'from_id as float' => [[$valid, ['from_id' => 1_000_035.5, 'from_type' => 'npc_corp', 'standing' => 2.5]]];
        yield 'from_id null' => [[$valid, ['from_id' => null, 'from_type' => 'npc_corp', 'standing' => 2.5]]];
        yield 'entry without from_type' => [[$valid, ['from_id' => 1_000_035, 'standing' => 2.5]]];
        yield 'from_type as integer' => [[$valid, ['from_id' => 1_000_035, 'from_type' => 7, 'standing' => 2.5]]];
        yield 'from_type null' => [[$valid, ['from_id' => 1_000_035, 'from_type' => null, 'standing' => 2.5]]];
        yield 'entry without standing' => [[$valid, ['from_id' => 1_000_035, 'from_type' => 'npc_corp']]];
        yield 'standing as string' => [[$valid, ['from_id' => 1_000_035, 'from_type' => 'npc_corp', 'standing' => '2.5']]];
        yield 'standing null' => [[$valid, ['from_id' => 1_000_035, 'from_type' => 'npc_corp', 'standing' => null]]];
    }

    private function standingLine(int $fromId, string $fromType, float $standing): CachedCharacterStanding
    {
        return (new CachedCharacterStanding())
            ->setCharacter($this->character)
            ->setFromId($fromId)
            ->setFromType($fromType)
            ->setStanding($standing)
            ->setCachedAt(new \DateTimeImmutable('2026-11-03T09:30:00+00:00'));
    }

    private function storedStandingRows(): array
    {
        $rows = array_map(
            static fn (CachedCharacterStanding $standing): array => [$standing->getFromId(), $standing->getFromType(), $standing->getStanding()],
            $this->storedStandings,
        );
        sort($rows);

        return $rows;
    }

    private function synchronize(array $esiResponse): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint, ?EveToken $token) use ($esiResponse): array {
            $this->esiEndpointCalls[] = [$endpoint, $token];

            return $esiResponse;
        });

        $standingRepository = $this->createStub(CachedCharacterStandingRepository::class);
        $standingRepository->method('findBy')->willReturnCallback(
            fn (): array => array_values($this->storedStandings),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if (!in_array($entity, $this->storedStandings, true)) {
                $this->storedStandings[] = $entity;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->storedStandings = array_values(array_filter(
                $this->storedStandings,
                static fn (object $stored): bool => $stored !== $entity,
            ));
        });

        (new CharacterStandingsSynchronizer($esiClient, $standingRepository, $entityManager))->sync($this->character, $this->token);
    }
}
