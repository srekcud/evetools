<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1394')]
final class PlanetarySettingsApiTest extends WebTestCase
{
    private const string SETTINGS_URI = '/api/planetary/settings';
    private const string LOCATIONS_URI = '/api/planetary/settings/locations';
    private const string UNKNOWN_LOCATION_MESSAGE = 'This location is not known to any of your characters.';
    private const int DEFAULT_STRUCTURE_ID = 1_049_588_174_021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int PREFERRED_STRUCTURE_ID = 1_028_858_195_007;
    private const string PREFERRED_STRUCTURE_NAME = 'Ashab - Preferred Fortizar';
    private const int ALICE_MAIN_EVE_ID = 2_113_940_001;
    private const int ALICE_SECOND_EVE_ID = 2_113_940_002;
    private const int BOB_EVE_ID = 2_113_940_003;
    private const int JITA_STATION_ID = 60_003_760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int AMARR_STATION_ID = 60_008_494;
    private const string AMARR_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';
    private const int ASHAB_FORTIZAR_ID = 1_030_000_000_123;
    private const string ASHAB_FORTIZAR_NAME = 'Ashab - Market Fortizar';
    private const int BOB_ONLY_KEEPSTAR_ID = 1_030_000_000_555;
    private const string BOB_ONLY_KEEPSTAR_NAME = 'Bob Private Keepstar';
    private const int STORED_HOME_ID = 1_030_000_000_777;
    private const string STORED_HOME_NAME = 'Old Home Raitaru';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextItemId = 1_139_400_000_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_MAIN_EVE_ID);
        $this->persistSecondCharacter($this->alice, 'Aldo Tarn', self::ALICE_SECOND_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
    }

    public function testTheSettingsRequireAuthentication(): void
    {
        $this->client->request('GET', self::SETTINGS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheCandidateLocationsRequireAuthentication(): void
    {
        $this->client->request('GET', self::LOCATIONS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPatchingTheSettingsRequiresAuthentication(): void
    {
        $this->client->request(
            'PATCH',
            self::SETTINGS_URI,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['coverageWeeks' => 4], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        $this->em->refresh($this->alice);
        self::assertSame(3, $this->alice->getPlanetaryCoverageWeeks());
    }

    public function testANewUserHasNoHomeLocationThreeWeeksOfCoverageAndTheConfiguredHub(): void
    {
        $body = $this->read($this->alice, self::SETTINGS_URI);

        self::assertSame('settings', $body['id']);
        self::assertArrayHasKey('homeLocationId', $body);
        self::assertNull($body['homeLocationId']);
        self::assertArrayHasKey('homeLocationName', $body);
        self::assertNull($body['homeLocationName']);
        self::assertSame(3, $body['coverageWeeks']);
        self::assertSame(self::DEFAULT_STRUCTURE_ID, $body['hubStructureId']);
        self::assertSame(self::DEFAULT_STRUCTURE_NAME, $body['hubStructureName']);
    }

    public function testTheStoredSettingsAndThePreferredMarketStructureAsHubAreRead(): void
    {
        $this->alice->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);
        $this->alice->setPlanetaryCoverageWeeks(9);
        $this->alice->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID);
        $this->alice->setPreferredMarketStructureName(self::PREFERRED_STRUCTURE_NAME);
        $this->em->flush();

        $body = $this->read($this->alice, self::SETTINGS_URI);

        self::assertSame(self::STORED_HOME_ID, $body['homeLocationId']);
        self::assertSame(self::STORED_HOME_NAME, $body['homeLocationName']);
        self::assertSame(9, $body['coverageWeeks']);
        self::assertSame(self::PREFERRED_STRUCTURE_ID, $body['hubStructureId']);
        self::assertSame(self::PREFERRED_STRUCTURE_NAME, $body['hubStructureName']);
    }

    public function testPatchingTheCoverageStoresItLeavesTheHomeLocationAndIsReadBack(): void
    {
        $this->alice->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);
        $this->em->flush();

        $patched = $this->patch($this->alice, ['coverageWeeks' => 4]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $patched['coverageWeeks']);
        self::assertSame(self::STORED_HOME_ID, $patched['homeLocationId']);
        $reread = $this->read($this->alice, self::SETTINGS_URI);
        self::assertSame(4, $reread['coverageWeeks']);
        self::assertSame(self::STORED_HOME_ID, $reread['homeLocationId']);
        self::assertSame(self::STORED_HOME_NAME, $reread['homeLocationName']);
        $this->em->refresh($this->alice);
        self::assertSame(4, $this->alice->getPlanetaryCoverageWeeks());
    }

    public function testTheBoundsOfOneAndFiftyTwoWeeksAreAccepted(): void
    {
        $this->patch($this->alice, ['coverageWeeks' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($this->alice);
        self::assertSame(1, $this->alice->getPlanetaryCoverageWeeks());

        $this->patch($this->alice, ['coverageWeeks' => 52]);
        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($this->alice);
        self::assertSame(52, $this->alice->getPlanetaryCoverageWeeks());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function refusedCoverageWeeks(): iterable
    {
        yield 'zero' => [0];
        yield 'above the maximum' => [53];
        yield 'negative' => [-1];
        yield 'beyond int4' => [2_147_483_648];
        yield 'not an integer' => [2.5];
        yield 'a string' => ['4'];
        yield 'null' => [null];
    }

    #[DataProvider('refusedCoverageWeeks')]
    public function testACoverageOutOfRangeOrOfTheWrongTypeIsRefusedWith422AndTheStoredValueIsKept(mixed $coverageWeeks): void
    {
        $this->patch($this->alice, ['coverageWeeks' => $coverageWeeks]);

        self::assertResponseStatusCodeSame(422);
        $this->em->refresh($this->alice);
        self::assertSame(3, $this->alice->getPlanetaryCoverageWeeks());
    }

    public function testACoverageOutOfRangeNamesTheCoverageWeeksField(): void
    {
        $body = $this->patch($this->alice, ['coverageWeeks' => 53]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['coverageWeeks'], array_column($body['violations'] ?? [], 'propertyPath'));
    }

    public function testAHomeLocationHeldByTheSecondCharacterOfTheUserIsStoredWithItsName(): void
    {
        $this->persistAsset($this->alice->getCharacters()->last(), self::AMARR_STATION_ID, 'station', self::AMARR_STATION_NAME, 'Amarr');

        $patched = $this->patch($this->alice, ['homeLocationId' => self::AMARR_STATION_ID]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::AMARR_STATION_ID, $patched['homeLocationId']);
        self::assertSame(self::AMARR_STATION_NAME, $patched['homeLocationName']);
        self::assertSame(3, $patched['coverageWeeks']);
        $this->em->refresh($this->alice);
        self::assertSame(self::AMARR_STATION_ID, $this->alice->getPlanetaryHomeLocationId());
        self::assertSame(self::AMARR_STATION_NAME, $this->alice->getPlanetaryHomeLocationName());
    }

    public function testAHomeLocationHeldOnlyByAnotherUserIsRefusedWith422AndNothingIsWritten(): void
    {
        $this->persistAsset($this->bob->getCharacters()->first(), self::BOB_ONLY_KEEPSTAR_ID, 'structure', self::BOB_ONLY_KEEPSTAR_NAME, 'Ashab');

        $body = $this->patch($this->alice, ['homeLocationId' => self::BOB_ONLY_KEEPSTAR_ID]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['homeLocationId', self::UNKNOWN_LOCATION_MESSAGE]], array_map(
            static fn (array $violation): array => [$violation['propertyPath'] ?? null, $violation['message'] ?? null],
            $body['violations'] ?? [],
        ));
        $this->em->refresh($this->alice);
        self::assertNull($this->alice->getPlanetaryHomeLocationId());
        self::assertNull($this->alice->getPlanetaryHomeLocationName());
    }

    public function testAHomeLocationHeldByNoCharacterIsRefusedWith422(): void
    {
        $this->patch($this->alice, ['homeLocationId' => self::ASHAB_FORTIZAR_ID]);

        self::assertResponseStatusCodeSame(422);
        $this->em->refresh($this->alice);
        self::assertNull($this->alice->getPlanetaryHomeLocationId());
    }

    public function testAHomeLocationBeyondTheIntegerRangeIsRefusedWith422(): void
    {
        $this->actAs($this->alice);
        $this->client->request(
            'PATCH',
            self::SETTINGS_URI,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"homeLocationId": 9223372036854775808}',
        );

        self::assertResponseStatusCodeSame(422);
        $this->em->refresh($this->alice);
        self::assertNull($this->alice->getPlanetaryHomeLocationId());
    }

    public function testANullHomeLocationAfterAChoiceClearsTheIdentifierAndTheName(): void
    {
        $this->persistAsset($this->alice->getCharacters()->first(), self::JITA_STATION_ID, 'station', self::JITA_STATION_NAME, 'Jita');
        $this->patch($this->alice, ['homeLocationId' => self::JITA_STATION_ID]);
        self::assertResponseStatusCodeSame(200);

        $patched = $this->patch($this->alice, ['homeLocationId' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('homeLocationId', $patched);
        self::assertNull($patched['homeLocationId']);
        self::assertArrayHasKey('homeLocationName', $patched);
        self::assertNull($patched['homeLocationName']);
        $this->em->refresh($this->alice);
        self::assertNull($this->alice->getPlanetaryHomeLocationId());
        self::assertNull($this->alice->getPlanetaryHomeLocationName());
    }

    public function testAPatchOfTheCoverageKeepsAStoredHomeLocationThatIsNoLongerACandidate(): void
    {
        $this->alice->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);
        $this->em->flush();

        $this->patch($this->alice, ['coverageWeeks' => 6]);

        self::assertResponseStatusCodeSame(200);
        $this->em->refresh($this->alice);
        self::assertSame(6, $this->alice->getPlanetaryCoverageWeeks());
        self::assertSame(self::STORED_HOME_ID, $this->alice->getPlanetaryHomeLocationId());
        self::assertSame(self::STORED_HOME_NAME, $this->alice->getPlanetaryHomeLocationName());
    }

    public function testAPatchOfTheReadOnlyHubFieldChangesNothing(): void
    {
        $this->alice->setPlanetaryHomeLocation(self::STORED_HOME_ID, self::STORED_HOME_NAME);
        $this->alice->setPlanetaryCoverageWeeks(5);
        $this->em->flush();

        $patched = $this->patch($this->alice, ['hubStructureId' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::DEFAULT_STRUCTURE_ID, $patched['hubStructureId']);
        self::assertSame(5, $patched['coverageWeeks']);
        self::assertSame(self::STORED_HOME_ID, $patched['homeLocationId']);
        $this->em->refresh($this->alice);
        self::assertSame(5, $this->alice->getPlanetaryCoverageWeeks());
        self::assertSame(self::STORED_HOME_ID, $this->alice->getPlanetaryHomeLocationId());
        self::assertNull($this->alice->getPreferredMarketStructureId());
    }

    public function testAnEmptyPatchChangesNothing(): void
    {
        $this->alice->setPlanetaryCoverageWeeks(5);
        $this->em->flush();

        $patched = $this->patch($this->alice, []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(5, $patched['coverageWeeks']);
        $this->em->refresh($this->alice);
        self::assertSame(5, $this->alice->getPlanetaryCoverageWeeks());
    }

    public function testTheCandidateLocationsMergeTheCharactersOfTheUserSortedByNameAndLeaveOutAnotherUser(): void
    {
        $this->persistAsset($this->alice->getCharacters()->first(), self::JITA_STATION_ID, 'station', self::JITA_STATION_NAME, 'Jita');
        $this->persistAsset($this->alice->getCharacters()->first(), self::AMARR_STATION_ID, 'station', self::AMARR_STATION_NAME, 'Amarr');
        $this->persistAsset($this->alice->getCharacters()->last(), self::AMARR_STATION_ID, 'station', self::AMARR_STATION_NAME, 'Amarr');
        $this->persistAsset($this->alice->getCharacters()->last(), self::ASHAB_FORTIZAR_ID, 'structure', self::ASHAB_FORTIZAR_NAME, 'Ashab');
        $this->persistAsset($this->bob->getCharacters()->first(), self::BOB_ONLY_KEEPSTAR_ID, 'structure', self::BOB_ONLY_KEEPSTAR_NAME, 'Ashab');

        $body = $this->read($this->alice, self::LOCATIONS_URI);

        self::assertSame('locations', $body['id']);
        self::assertSame([
            ['locationId' => self::AMARR_STATION_ID, 'locationName' => self::AMARR_STATION_NAME, 'solarSystemName' => 'Amarr'],
            ['locationId' => self::ASHAB_FORTIZAR_ID, 'locationName' => self::ASHAB_FORTIZAR_NAME, 'solarSystemName' => 'Ashab'],
            ['locationId' => self::JITA_STATION_ID, 'locationName' => self::JITA_STATION_NAME, 'solarSystemName' => 'Jita'],
        ], $body['locations']);
    }

    public function testAUserWithoutAssetGetsAnEmptyCandidateList(): void
    {
        $this->persistAsset($this->bob->getCharacters()->first(), self::BOB_ONLY_KEEPSTAR_ID, 'structure', self::BOB_ONLY_KEEPSTAR_NAME, 'Ashab');

        $body = $this->read($this->alice, self::LOCATIONS_URI);

        self::assertArrayHasKey('locations', $body);
        self::assertSame([], $body['locations']);
    }

    private function actAs(User $user): void
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(User $user, string $uri): array
    {
        $this->actAs($user);
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return $this->responseJson();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function patch(User $user, array $payload): array
    {
        $this->actAs($user);
        $this->client->request(
            'PATCH',
            self::SETTINGS_URI,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR),
        );

        return $this->responseJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistSecondCharacter(User $user, string $name, int $eveCharacterId): void
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->flush();
    }

    private function persistAsset(Character $character, int $locationId, string $locationType, string $locationName, string $solarSystemName): void
    {
        $this->em->persist((new CachedAsset())
            ->setItemId($this->nextItemId++)
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setQuantity(10)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType($locationType)
            ->setSolarSystemName($solarSystemName)
            ->setCharacter($character)
            ->setIsCorporationAsset(false));
        $this->em->flush();
    }
}
