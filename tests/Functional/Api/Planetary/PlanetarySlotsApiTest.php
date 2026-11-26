<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1384')]
final class PlanetarySlotsApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T09:00:00+00:00';

    private const int ALICE_MAIN_ID = 2_113_841_001;
    private const int ALICE_IDLE_ID = 2_113_841_002;
    private const int ALICE_UNSYNCED_ID = 2_113_841_003;
    private const int BOB_MAIN_ID = 2_113_841_011;
    private const int CAROL_MAIN_ID = 2_113_841_021;
    private const int CAROL_IDLE_ID = 2_113_841_022;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private User $carol;
    private int $nextPlanetId = 40_113_841;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->alice = $this->persistUser(
            $this->newCharacter(self::ALICE_MAIN_ID, 'Alice Tarn'),
            $this->newCharacter(self::ALICE_IDLE_ID, 'Alice Idle'),
            $this->newCharacter(self::ALICE_UNSYNCED_ID, 'Alice Unsynced'),
        );
        $this->bob = $this->persistUser($this->newCharacter(self::BOB_MAIN_ID, 'Bob Kess'));
        $this->carol = $this->persistUser(
            $this->newCharacter(self::CAROL_MAIN_ID, 'Carol Vane'),
            $this->newCharacter(self::CAROL_IDLE_ID, 'Carol Idle'),
        );

        $this->persistColonies(self::ALICE_MAIN_ID, 2);
        $this->persistColonies(self::ALICE_UNSYNCED_ID, 1);
        $this->persistColonies(self::BOB_MAIN_ID, 3);
        $this->persistColonies(self::CAROL_MAIN_ID, 2);
        $this->persistConsolidationLevel(self::ALICE_MAIN_ID, 4);
        $this->persistConsolidationLevel(self::ALICE_IDLE_ID, 0);
        $this->persistConsolidationLevel(self::BOB_MAIN_ID, 5);
        $this->persistConsolidationLevel(self::CAROL_MAIN_ID, 3);
        $this->persistConsolidationLevel(self::CAROL_IDLE_ID, 0);
        $this->em->flush();
    }

    public function testSlotsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/slots', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testEachCharacterOfTheUserIsListedWithItsColoniesUsedLevelAndMaximum(): void
    {
        $this->client->loginUser($this->alice, 'api');

        $json = $this->getSlots();

        $byCharacterId = array_column($json['characters'], null, 'characterId');
        self::assertEqualsCanonicalizing(
            [self::ALICE_MAIN_ID, self::ALICE_IDLE_ID, self::ALICE_UNSYNCED_ID],
            array_keys($byCharacterId),
        );
        self::assertSame(
            ['characterId', 'characterName', 'coloniesUsed', 'consolidationLevel', 'isMain', 'maxColonies'],
            $this->sortedKeys($byCharacterId[self::ALICE_MAIN_ID]),
        );
        self::assertSame('Alice Tarn', $byCharacterId[self::ALICE_MAIN_ID]['characterName']);
        self::assertTrue($byCharacterId[self::ALICE_MAIN_ID]['isMain']);
        self::assertSame(2, $byCharacterId[self::ALICE_MAIN_ID]['coloniesUsed']);
        self::assertSame(4, $byCharacterId[self::ALICE_MAIN_ID]['consolidationLevel']);
        self::assertSame(5, $byCharacterId[self::ALICE_MAIN_ID]['maxColonies']);
    }

    public function testACharacterWithoutColonyIsListedWithItsLevelZeroAndOneSlot(): void
    {
        $this->client->loginUser($this->alice, 'api');

        $json = $this->getSlots();

        $idle = array_column($json['characters'], null, 'characterId')[self::ALICE_IDLE_ID] ?? null;
        self::assertNotNull($idle);
        self::assertSame('Alice Idle', $idle['characterName']);
        self::assertFalse($idle['isMain']);
        self::assertSame(0, $idle['coloniesUsed']);
        self::assertSame(0, $idle['consolidationLevel']);
        self::assertSame(1, $idle['maxColonies']);
    }

    public function testACharacterWithoutSkillLineHasUnknownLevelAndMaximumAndTheTotalMaximumIsUnknown(): void
    {
        $this->client->loginUser($this->alice, 'api');

        $json = $this->getSlots();

        $unsynced = array_column($json['characters'], null, 'characterId')[self::ALICE_UNSYNCED_ID] ?? null;
        self::assertNotNull($unsynced);
        self::assertSame(1, $unsynced['coloniesUsed']);
        self::assertArrayHasKey('consolidationLevel', $unsynced);
        self::assertNull($unsynced['consolidationLevel']);
        self::assertArrayHasKey('maxColonies', $unsynced);
        self::assertNull($unsynced['maxColonies']);
        self::assertSame(3, $json['totalUsed']);
        self::assertArrayHasKey('totalMax', $json);
        self::assertNull($json['totalMax']);
    }

    public function testTotalsAddUpEveryCharacterWhenAllLevelsAreKnown(): void
    {
        $this->client->loginUser($this->carol, 'api');

        $json = $this->getSlots();

        self::assertCount(2, $json['characters']);
        self::assertSame(2, $json['totalUsed']);
        self::assertSame(5, $json['totalMax']);
    }

    public function testAnotherUserSeesOnlyTheirOwnCharactersAndColonies(): void
    {
        $this->client->loginUser($this->bob, 'api');

        $json = $this->getSlots();

        self::assertSame([self::BOB_MAIN_ID], array_column($json['characters'], 'characterId'));
        self::assertSame(3, $json['characters'][0]['coloniesUsed']);
        self::assertSame(5, $json['characters'][0]['consolidationLevel']);
        self::assertSame(6, $json['characters'][0]['maxColonies']);
        self::assertSame(3, $json['totalUsed']);
        self::assertSame(6, $json['totalMax']);
    }

    private function newCharacter(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_538_001)
            ->setCorporationName('Verhoeven Deep Core');
    }

    private function persistUser(Character $main, Character ...$others): User
    {
        $user = new User();
        $this->em->persist($user);
        $user->addCharacter($main);
        $user->setMainCharacter($main);
        $this->em->persist($main);
        foreach ($others as $other) {
            $user->addCharacter($other);
            $this->em->persist($other);
        }
        $this->em->flush();

        return $user;
    }

    private function characterOf(int $eveCharacterId): Character
    {
        $character = $this->em->getRepository(Character::class)->findOneBy(['eveCharacterId' => $eveCharacterId]);
        self::assertNotNull($character);

        return $character;
    }

    private function persistColonies(int $eveCharacterId, int $count): void
    {
        $character = $this->characterOf($eveCharacterId);
        for ($index = 0; $index < $count; ++$index) {
            $planetId = $this->nextPlanetId++;
            $this->em->persist(
                (new PlanetaryColony())
                    ->setCharacter($character)
                    ->setPlanetId($planetId)
                    ->setPlanetName('Planet '.$planetId)
                    ->setPlanetType('temperate')
                    ->setSolarSystemId(30_001_538)
                    ->setSolarSystemName('Aldranette')
                    ->setUpgradeLevel(4)
                    ->setNumPins(3)
                    ->setLastUpdate(new \DateTimeImmutable(self::NOW)),
            );
        }
    }

    private function persistConsolidationLevel(int $eveCharacterId, int $level): void
    {
        $this->em->persist(
            (new CachedCharacterSkill())
                ->setCharacter($this->characterOf($eveCharacterId))
                ->setSkillId(CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION)
                ->setLevel($level)
                ->setIsManual(false)
                ->setCachedAt(new \DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSlots(): array
    {
        $this->client->request('GET', '/api/planetary/slots', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
