<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Assets;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-671')]
final class CharacterAssetsApiTest extends WebTestCase
{
    private const int EVE_CHARACTER_ID = 2_113_671_001;
    private const int JITA_STATION_ID = 60_003_760;
    private const int TRITANIUM_ITEM_ID = 1_050_000_671_001;
    private const int TRITANIUM_TYPE_ID = 34;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnAssetWithoutCustomNameOrSolarSystemExposesThoseFieldsAsNull(): void
    {
        $user = $this->createPilotOwningAnUnnamedAsset();

        $this->client->loginUser($user, 'api');
        $this->client->request(
            'GET',
            '/api/me/characters/' . $user->getMainCharacter()->getId()->toRfc4122() . '/assets',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $item = $body['items'][0];
        foreach (['typeName', 'categoryId', 'solarSystemId', 'solarSystemName', 'itemName', 'divisionName'] as $nullableField) {
            self::assertArrayHasKey($nullableField, $item);
        }
        self::assertNull($item['itemName']);
        self::assertNull($item['solarSystemName']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inventoryFormats(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'json-ld' => ['application/ld+json'];
    }

    #[Group('issue-874')]
    #[DataProvider('inventoryFormats')]
    public function testTheInventoryListIsServedWithoutLinkedDataKeysWhateverTheFormat(string $accept): void
    {
        $user = $this->createPilotOwningAnUnnamedAsset();

        $this->client->loginUser($user, 'api');
        $this->client->request(
            'GET',
            '/api/me/characters/' . $user->getMainCharacter()->getId()->toRfc4122() . '/assets',
            server: ['HTTP_ACCEPT' => $accept],
        );

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['characterId', 'total', 'items'], array_keys($body));
        self::assertSame(1, $body['total']);
        self::assertSame(
            [
                'id', 'itemId', 'typeId', 'typeName', 'categoryId', 'quantity', 'locationId', 'locationName',
                'locationType', 'locationFlag', 'solarSystemId', 'solarSystemName', 'itemName', 'divisionName',
                'isSingleton', 'canResolveItemName', 'blueprint', 'cachedAt',
            ],
            array_keys($body['items'][0]),
        );
    }

    private function createPilotOwningAnUnnamedAsset(): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Ilse Varrow')
            ->setCorporationId(98_671_001)
            ->setCorporationName('Varrow Logistics');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist((new CachedAsset())
            ->setItemId(self::TRITANIUM_ITEM_ID)
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setQuantity(1_000)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setCharacter($character)
            ->setIsCorporationAsset(false));
        $this->em->flush();

        return $user;
    }
}
