<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\AssetSyncChange;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Dto\AssetQuantityChange;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-888')]
final class AssetChangesApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_888_001;
    private const int JITA_STATION_ID = 60_003_760;
    private const int TYPE_DRAKE = 24_698;
    private const int TYPE_TRITANIUM = 34;
    private const int VISIBLE_DIVISION_NUMBER = 2;
    private const string STATION_LABEL = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string PREVIOUS_SYNC = '2026-11-13T08:00:00+00:00';
    private const string SYNC = '2026-11-13T09:00:00+00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $eveCharacterSequence = 2_118_880_000;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testACharacterReadsItsLastDiffSortedByLocationThenType(): void
    {
        $owner = $this->persistUser('Ilse Varrow');
        $character = $owner->getMainCharacter();
        $this->persistCharacterChange($character, 'Zeta box — ' . self::STATION_LABEL, self::TYPE_TRITANIUM, 'Tritanium', -4);
        $this->persistCharacterChange($character, 'Alpha box — ' . self::STATION_LABEL, self::TYPE_DRAKE, 'Drake', 3);
        $this->em->flush();

        $body = $this->get($owner, '/api/me/characters/' . $character->getId()->toRfc4122() . '/assets/changes');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $body['total']);
        self::assertSame(self::SYNC, $body['syncedAt']);
        self::assertSame(self::PREVIOUS_SYNC, $body['previousSyncedAt']);
        self::assertSame(
            [['Drake', 3], ['Tritanium', -4]],
            array_map(static fn (array $change): array => [$change['typeName'], $change['quantityDelta']], $body['changes']),
        );
        self::assertSame(
            ['typeId', 'typeName', 'locationId', 'locationName', 'divisionName', 'quantityDelta'],
            array_keys($body['changes'][0]),
        );
    }

    public function testACharacterWithoutAnyDiffGetsAnEmptyListAndNullDates(): void
    {
        $owner = $this->persistUser('Ilse Varrow');
        $this->em->flush();

        $body = $this->get($owner, '/api/me/characters/' . $owner->getMainCharacter()->getId()->toRfc4122() . '/assets/changes');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['changes']);
        self::assertNull($body['syncedAt']);
        self::assertNull($body['previousSyncedAt']);
    }

    public function testTheDiffOfAnotherUsersCharacterIsNotFound(): void
    {
        $owner = $this->persistUser('Ilse Varrow');
        $stranger = $this->persistUser('Bob Tamm');
        $this->persistCharacterChange($owner->getMainCharacter(), self::STATION_LABEL, self::TYPE_DRAKE, 'Drake', 3);
        $this->em->flush();

        $this->get($stranger, '/api/me/characters/' . $owner->getMainCharacter()->getId()->toRfc4122() . '/assets/changes');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCorporationDiffOnlyCarriesTheDivisionsVisibleToTheViewer(): void
    {
        $viewer = $this->persistUser('Ilse Varrow', self::CORPORATION_ID);
        $this->persistCorporationDivision(self::VISIBLE_DIVISION_NUMBER, 'Ships');
        $this->persistCorporationDivision(1, 'Minerals');
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([self::VISIBLE_DIVISION_NUMBER]));
        $this->persistCorporationChange('Ships', self::TYPE_DRAKE, 'Drake', -3);
        $this->persistCorporationChange('Minerals', self::TYPE_TRITANIUM, 'Tritanium', 9);
        $this->em->flush();

        $body = $this->get($viewer, '/api/me/corporation/assets/changes');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $body['total']);
        self::assertSame('Drake', $body['changes'][0]['typeName']);
        self::assertSame('Ships', $body['changes'][0]['divisionName']);
        self::assertSame(-3, $body['changes'][0]['quantityDelta']);
    }

    public function testTheCorporationDiffIsEmptyWhenTheCorporationSharesNoDivision(): void
    {
        $viewer = $this->persistUser('Ilse Varrow', self::CORPORATION_ID);
        $this->persistCorporationDivision(self::VISIBLE_DIVISION_NUMBER, 'Ships');
        $this->persistCorporationChange('Ships', self::TYPE_DRAKE, 'Drake', -3);
        $this->em->flush();

        $body = $this->get($viewer, '/api/me/corporation/assets/changes');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['changes']);
    }

    public function testTheCorporationDiffOfAnotherCorporationIsNeverReturned(): void
    {
        $viewer = $this->persistUser('Ilse Varrow', self::CORPORATION_ID);
        $this->persistCorporationDivision(self::VISIBLE_DIVISION_NUMBER, 'Ships');
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([self::VISIBLE_DIVISION_NUMBER]));
        $this->em->persist(new AssetSyncChange(
            null,
            self::CORPORATION_ID + 1,
            new AssetQuantityChange(self::TYPE_DRAKE, 'Drake', self::JITA_STATION_ID, self::STATION_LABEL, 'Ships', -3),
            new \DateTimeImmutable(self::SYNC),
            new \DateTimeImmutable(self::PREVIOUS_SYNC),
        ));
        $this->em->flush();

        $body = $this->get($viewer, '/api/me/corporation/assets/changes');

        self::assertSame(0, $body['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(User $user, string $uri): array
    {
        $this->client->loginUser($user, 'api');
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function persistUser(string $name, int $corporationId = 98_888_900): User
    {
        $character = (new Character())
            ->setEveCharacterId(++$this->eveCharacterSequence)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName('Varrow Logistics');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function persistCharacterChange(Character $character, string $locationName, int $typeId, string $typeName, int $delta): void
    {
        $this->em->persist(new AssetSyncChange(
            $character,
            null,
            new AssetQuantityChange($typeId, $typeName, self::JITA_STATION_ID, $locationName, null, $delta),
            new \DateTimeImmutable(self::SYNC),
            new \DateTimeImmutable(self::PREVIOUS_SYNC),
        ));
    }

    private function persistCorporationChange(string $divisionName, int $typeId, string $typeName, int $delta): void
    {
        $this->em->persist(new AssetSyncChange(
            null,
            self::CORPORATION_ID,
            new AssetQuantityChange($typeId, $typeName, self::JITA_STATION_ID, self::STATION_LABEL, $divisionName, $delta),
            new \DateTimeImmutable(self::SYNC),
            new \DateTimeImmutable(self::PREVIOUS_SYNC),
        ));
    }

    private function persistCorporationDivision(int $number, string $divisionName): void
    {
        $this->em->persist((new CachedAsset())
            ->setItemId(1_088_800_000_000 + $number)
            ->setTypeId(self::TYPE_TRITANIUM)
            ->setTypeName('Tritanium')
            ->setQuantity(1)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName(self::STATION_LABEL)
            ->setLocationType('station')
            ->setLocationFlag('CorpSAG' . $number)
            ->setDivisionName($divisionName)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true));
    }
}
