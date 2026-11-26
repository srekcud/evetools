<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1383')]
final class PlanetaryColonyTierAndStorageApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T09:00:00+00:00';

    private const int WITHOUT_STORAGE_ID = 2_113_831_001;
    private const int UNREADABLE_STORAGE_ID = 2_113_831_002;

    private const int UNREADABLE_PIN_TYPE = 2_113_831;
    private const int UNREADABLE_STORAGE_TYPE = 2_113_832;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private int $nextPlanetId = 40_113_831;
    private int $nextPinId = 1;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $withoutStorage = $this->newCharacter(self::WITHOUT_STORAGE_ID, 'Plain Pilot');
        $unreadableStorage = $this->newCharacter(self::UNREADABLE_STORAGE_ID, 'Storage Pilot');
        $this->owner = new User();
        $this->em->persist($this->owner);
        foreach ([$withoutStorage, $unreadableStorage] as $character) {
            $this->owner->addCharacter($character);
            $this->em->persist($character);
        }
        $this->owner->setMainCharacter($withoutStorage);
        $this->em->flush();

        $this->persistColony($withoutStorage, self::UNREADABLE_PIN_TYPE, null);
        $this->persistColony($unreadableStorage, self::UNREADABLE_STORAGE_TYPE, 'Barren Storage Facility');
        $this->em->flush();
    }

    public function testListCarriesTheTierKeyEvenWhenTheColonyHasNoFactory(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::WITHOUT_STORAGE_ID);

        self::assertArrayHasKey('tier', $row);
        self::assertNull($row['tier']);
    }

    public function testListCarriesAFalseStorageFullForAColonyWithoutStorageFacility(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::WITHOUT_STORAGE_ID);

        self::assertArrayHasKey('storageFull', $row);
        self::assertFalse($row['storageFull']);
    }

    public function testListCarriesANullStorageFullKeyWhenTheStorageFacilityIsUnreadable(): void
    {
        $this->client->loginUser($this->owner, 'api');

        $row = $this->rowOfCharacter(self::UNREADABLE_STORAGE_ID);

        self::assertArrayHasKey('storageFull', $row);
        self::assertNull($row['storageFull']);
        self::assertArrayHasKey('tier', $row);
        self::assertNull($row['tier']);
    }

    public function testDetailCarriesTheSameTierAndStorageFullKeys(): void
    {
        $colony = $this->em->getRepository(PlanetaryColony::class)->findOneBy([
            'character' => $this->em->getRepository(Character::class)->findOneBy(['eveCharacterId' => self::UNREADABLE_STORAGE_ID]),
        ]);
        self::assertNotNull($colony);
        $this->client->loginUser($this->owner, 'api');

        $this->client->request('GET', '/api/planetary/'.$colony->getId()?->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('tier', $detail);
        self::assertNull($detail['tier']);
        self::assertArrayHasKey('storageFull', $detail);
        self::assertNull($detail['storageFull']);
    }

    private function newCharacter(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_538_001)
            ->setCorporationName('Verhoeven Deep Core');
    }

    private function persistColony(Character $character, int $pinTypeId, ?string $pinTypeName): void
    {
        $planetId = $this->nextPlanetId++;
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName('Planet '.$planetId)
            ->setPlanetType('barren')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(3)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
        $colony->addPin(
            (new PlanetaryPin())
                ->setPinId($this->nextPinId++)
                ->setTypeId($pinTypeId)
                ->setTypeName($pinTypeName)
                ->setContents([['type_id' => 2_113_833, 'amount' => 100]]),
        );
        $this->em->persist($colony);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOfCharacter(int $eveCharacterId): array
    {
        $this->client->request('GET', '/api/planetary', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $rows = array_column($rows, null, 'characterId');
        self::assertArrayHasKey($eveCharacterId, $rows);

        return $rows[$eveCharacterId];
    }
}
