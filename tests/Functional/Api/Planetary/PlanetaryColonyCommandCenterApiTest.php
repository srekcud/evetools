<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1385')]
final class PlanetaryColonyCommandCenterApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T09:00:00+00:00';

    private const int TRAINED_ID = 2_113_851_001;
    private const int EQUAL_ID = 2_113_851_002;
    private const int UNSYNCED_ID = 2_113_851_003;
    private const int OTHER_USER_ID = 2_113_851_011;

    private const int UNREADABLE_COMMAND_CENTER_TYPE = 2_113_851;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $stranger;
    private int $nextPlanetId = 40_113_851;
    private int $nextPinId = 1;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->owner = $this->persistUser(
            $this->newCharacter(self::TRAINED_ID, 'Trained Pilot'),
            $this->newCharacter(self::EQUAL_ID, 'Equal Pilot'),
            $this->newCharacter(self::UNSYNCED_ID, 'Unsynced Pilot'),
        );
        $this->stranger = $this->persistUser($this->newCharacter(self::OTHER_USER_ID, 'Stranger Pilot'));

        $this->persistColony(self::TRAINED_ID, 3);
        $this->persistColony(self::EQUAL_ID, 3);
        $this->persistColony(self::UNSYNCED_ID, 3);
        $this->persistColony(self::OTHER_USER_ID, 1);
        $this->persistSkillLevel(self::TRAINED_ID, 4);
        $this->persistSkillLevel(self::EQUAL_ID, 3);
        $this->persistSkillLevel(self::OTHER_USER_ID, 5);
        $this->em->flush();
    }

    public function testColonyOfACharacterWhoseSkillExceedsTheCommandCenterLevelIsUpgradable(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::TRAINED_ID);

        self::assertSame(3, $row['upgradeLevel']);
        self::assertSame(4, $row['commandCenterSkillLevel']);
        self::assertTrue($row['commandCenterUpgradable']);
    }

    public function testColonyOfACharacterWhoseSkillEqualsTheCommandCenterLevelIsNotUpgradable(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::EQUAL_ID);

        self::assertSame(3, $row['commandCenterSkillLevel']);
        self::assertFalse($row['commandCenterUpgradable']);
    }

    public function testColonyOfACharacterWithoutSkillLineHasAnUnknownSkillAndAnUnknownUpgradeSignal(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::UNSYNCED_ID);

        self::assertArrayHasKey('commandCenterSkillLevel', $row);
        self::assertNull($row['commandCenterSkillLevel']);
        self::assertArrayHasKey('commandCenterUpgradable', $row);
        self::assertNull($row['commandCenterUpgradable']);
    }

    public function testColonyWhoseCommandCenterTypeIsUnreadableHasUnknownCpuAndPowergridFigures(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::TRAINED_ID);

        foreach (['cpuUsed', 'cpuMax', 'powergridUsed', 'powergridMax'] as $field) {
            self::assertArrayHasKey($field, $row);
            self::assertNull($row[$field]);
        }
    }

    public function testListOnlyCarriesTheColoniesOfTheLoggedInUser(): void
    {
        $this->client->loginUser($this->stranger, 'api');

        $rows = $this->getColonies();

        self::assertSame([self::OTHER_USER_ID], array_column($rows, 'characterId'));
        self::assertSame(5, $rows[0]['commandCenterSkillLevel']);
        self::assertFalse($rows[0]['commandCenterUpgradable']);
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

    private function persistColony(int $eveCharacterId, int $upgradeLevel): void
    {
        $planetId = $this->nextPlanetId++;
        $colony = (new PlanetaryColony())
            ->setCharacter($this->characterOf($eveCharacterId))
            ->setPlanetId($planetId)
            ->setPlanetName('Planet '.$planetId)
            ->setPlanetType('barren')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel($upgradeLevel)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
        $colony->addPin(
            (new PlanetaryPin())
                ->setPinId($this->nextPinId++)
                ->setTypeId(self::UNREADABLE_COMMAND_CENTER_TYPE),
        );
        $this->em->persist($colony);
    }

    private function persistSkillLevel(int $eveCharacterId, int $level): void
    {
        $this->em->persist(
            (new CachedCharacterSkill())
                ->setCharacter($this->characterOf($eveCharacterId))
                ->setSkillId(CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES)
                ->setLevel($level)
                ->setIsManual(false)
                ->setCachedAt(new \DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOfCharacter(int $eveCharacterId): array
    {
        $rows = array_column($this->getColonies(), null, 'characterId');
        self::assertArrayHasKey($eveCharacterId, $rows);

        return $rows[$eveCharacterId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getColonies(): array
    {
        $this->client->request('GET', '/api/planetary', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
