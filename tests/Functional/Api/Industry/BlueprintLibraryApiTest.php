<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Message\SyncUserBlueprints;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-357')]
final class BlueprintLibraryApiTest extends WebTestCase
{
    private const string PERSONAL_SCOPE = 'esi-characters.read_blueprints.v1';
    private const string CORPORATION_SCOPE = 'esi-corporations.read_blueprints.v1';
    private const int CORPORATION_ID = 98_357_301;
    private const string CORPORATION_NAME = 'Solberg Industries';
    private const int OTHER_CORPORATION_ID = 98_357_302;
    private const int CATEGORY_ID = 357;
    private const int GROUP_ID = 357_001;
    private const int DRAKE_BLUEPRINT = 357_101;
    private const int HAMMERHEAD_II_BLUEPRINT = 357_102;
    private const int HURRICANE_BLUEPRINT = 357_103;
    private const int VEXOR_BLUEPRINT = 357_104;
    private const int RIFTER_BLUEPRINT = 357_105;
    private const int DRAKE_LOCATION_ID = 60_003_760;
    private const int CORPORATION_HANGAR_ID = 1_040_000_000_357;

    private static int $nextEveCharacterId = 2_113_357_301;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedTypeNames();
    }

    public function testReadsThePersonalBlueprintsAndTheCorporationBlueprintsOfTheVisibleDivisionsOnly(): void
    {
        $aria = $this->persistPilot('Aria Solberg', self::CORPORATION_ID, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $other = $this->persistPilot('Corin Vale', self::OTHER_CORPORATION_ID, [self::PERSONAL_SCOPE]);
        $this->em->persist((new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([3])->setConfiguredBy($aria));
        $this->persistPersonalBlueprint($aria, 1_001, self::DRAKE_BLUEPRINT, -1, -1, 10, 20);
        $this->persistPersonalBlueprint($other, 1_002, self::VEXOR_BLUEPRINT, -1, -1, 4, 8);
        $this->persistCorporationBlueprint(self::CORPORATION_ID, 1_003, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3);
        $this->persistCorporationBlueprint(self::CORPORATION_ID, 1_004, self::HURRICANE_BLUEPRINT, -2, 30, 6, 12, 5);
        $this->persistCorporationBlueprint(self::OTHER_CORPORATION_ID, 1_005, self::RIFTER_BLUEPRINT, -1, -1, 8, 16, 3);
        $this->em->flush();

        $this->client->loginUser($aria, 'api');
        $this->request('GET', '/api/industry/blueprints');

        self::assertResponseStatusCodeSame(200);
        $library = $this->responseJson();
        self::assertSame('known', $library['corporationState']);
        self::assertSame(
            [
                [
                    'count' => 1,
                    'divisionNumber' => null,
                    'kind' => 'bpo',
                    'locationId' => self::DRAKE_LOCATION_ID,
                    'me' => 10,
                    'name' => 'Drake Blueprint',
                    'ownerName' => 'Aria Solberg',
                    'runs' => null,
                    'scope' => 'personal',
                    'te' => 20,
                    'typeId' => self::DRAKE_BLUEPRINT,
                ],
                [
                    'count' => 1,
                    'divisionNumber' => 3,
                    'kind' => 'bpc',
                    'locationId' => self::CORPORATION_HANGAR_ID,
                    'me' => 2,
                    'name' => 'Hammerhead II Blueprint',
                    'ownerName' => self::CORPORATION_NAME,
                    'runs' => 50,
                    'scope' => 'corporation',
                    'te' => 4,
                    'typeId' => self::HAMMERHEAD_II_BLUEPRINT,
                ],
            ],
            array_map(static function (array $row): array {
                ksort($row);

                return $row;
            }, $library['blueprints']),
        );
    }

    public function testServesOnlyThePersonalBlueprintsWhenNoCharacterOfTheCorporationHasTheCorporationScope(): void
    {
        $aria = $this->persistPilot('Aria Solberg', self::CORPORATION_ID, [self::PERSONAL_SCOPE]);
        $this->em->persist((new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([3])->setConfiguredBy($aria));
        $this->persistPersonalBlueprint($aria, 2_001, self::DRAKE_BLUEPRINT, -1, -1, 10, 20);
        $this->persistCorporationBlueprint(self::CORPORATION_ID, 2_002, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3);
        $this->em->flush();

        $this->client->loginUser($aria, 'api');
        $this->request('GET', '/api/industry/blueprints');

        self::assertResponseStatusCodeSame(200);
        $library = $this->responseJson();
        self::assertSame('no_director', $library['corporationState']);
        self::assertSame([self::DRAKE_BLUEPRINT], array_column($library['blueprints'], 'typeId'));
    }

    public function testLibraryIsUnauthorizedWithoutAToken(): void
    {
        $this->request('GET', '/api/industry/blueprints');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshQueuesTheBlueprintsSyncOfTheUserAndAnswersPending(): void
    {
        $aria = $this->persistPilot('Aria Solberg', self::CORPORATION_ID, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->em->flush();

        $this->client->loginUser($aria, 'api');
        $this->request('POST', '/api/industry/blueprints/refresh');

        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->responseJson()['status']);
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        self::assertEquals(
            [new SyncUserBlueprints($aria->getId()->toRfc4122())],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }

    private function seedTypeNames(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Test Blueprint');
        $this->em->persist($category);
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Test Blueprint Group')->setCategory($category);
        $this->em->persist($group);
        foreach ([
            self::DRAKE_BLUEPRINT => 'Drake Blueprint',
            self::HAMMERHEAD_II_BLUEPRINT => 'Hammerhead II Blueprint',
            self::HURRICANE_BLUEPRINT => 'Hurricane Blueprint',
            self::VEXOR_BLUEPRINT => 'Vexor Blueprint',
            self::RIFTER_BLUEPRINT => 'Rifter Blueprint',
        ] as $typeId => $name) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group)->setVolume(0.1));
        }
    }

    private function persistPilot(string $name, int $corporationId, array $scopes): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName($corporationId === self::CORPORATION_ID ? self::CORPORATION_NAME : 'Vale Freight');
        $token = (new EveToken())
            ->setAccessToken('access-' . $name)
            ->setRefreshTokenEncrypted('refresh-' . $name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes);
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->persist($token);

        return $user;
    }

    private function persistPersonalBlueprint(User $user, int $itemId, int $typeId, int $quantity, int $runs, int $materialEfficiency, int $timeEfficiency): void
    {
        $this->em->persist(
            (new OwnedBlueprint())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setLocationId(self::DRAKE_LOCATION_ID)
                ->setLocationFlag('Hangar')
                ->setQuantity($quantity)
                ->setRuns($runs)
                ->setMaterialEfficiency($materialEfficiency)
                ->setTimeEfficiency($timeEfficiency)
                ->setCharacter($user->getMainCharacter())
                ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00')),
        );
    }

    private function persistCorporationBlueprint(int $corporationId, int $itemId, int $typeId, int $quantity, int $runs, int $materialEfficiency, int $timeEfficiency, int $divisionNumber): void
    {
        $this->em->persist(
            (new OwnedBlueprint())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setLocationId(self::CORPORATION_HANGAR_ID)
                ->setLocationFlag('CorpSAG' . $divisionNumber)
                ->setQuantity($quantity)
                ->setRuns($runs)
                ->setMaterialEfficiency($materialEfficiency)
                ->setTimeEfficiency($timeEfficiency)
                ->setCorporationId($corporationId)
                ->setDivisionNumber($divisionNumber)
                ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00')),
        );
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
