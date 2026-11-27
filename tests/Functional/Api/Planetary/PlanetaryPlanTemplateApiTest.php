<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1405')]
final class PlanetaryPlanTemplateApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/planetary/templates';
    private const int AQUEOUS_LIQUIDS = 990_010;
    private const int WATER = 990_011;
    private const int COOLANT = 990_021;
    private const int ROBOTICS = 990_031;
    private const int BROADCAST_NODE = 990_041;
    private const int TRITANIUM = 34;
    private const int NAME_LIMIT = 100;
    private const int TEMPLATE_LIMIT = 50;
    private const int ALICE_EVE_ID = 2_114_050_001;
    private const int BOB_EVE_ID = 2_114_050_002;
    private const string UNKNOWN_ID = 'c4e8a1f6-2b97-4d03-8a5e-71f0d9b3c624';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;

    private ?User $actingUser = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedSchematics();
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
    }

    public function testEveryOperationRequiresAuthentication(): void
    {
        $existing = $this->persistTemplate($this->alice, 'Coolant line', '2026-11-20T09:00:00+00:00');
        $this->em->flush();

        foreach ([['GET', self::ENDPOINT], ['POST', self::ENDPOINT], ['GET', self::ENDPOINT.'/'.$existing->getId()], ['DELETE', self::ENDPOINT.'/'.$existing->getId()]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: $method === 'POST' ? json_encode($this->body(), JSON_THROW_ON_ERROR) : null);

            self::assertResponseStatusCodeSame(401, sprintf('%s %s', $method, $uri));
        }
        self::assertSame(1, $this->storedCount($this->alice));
    }

    public function testAValidPostCreatesTheTemplateWithItsFieldsAndTheNextGetReturnsIt(): void
    {
        $body = $this->body([
            'name' => 'Coolant and robotics',
            'productions' => [['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]],
            'choices' => [self::WATER => 'buy', self::ROBOTICS => 'produce'],
            'settings' => ['purchaseSource' => 'jita-buy', 'saleSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6],
        ]);
        $this->actAs($this->alice);

        $created = $this->send('POST', $body);
        self::assertResponseStatusCodeSame(201);
        $listed = $this->send('GET');
        self::assertResponseStatusCodeSame(200);

        self::assertTrue(Uuid::isValid($created['id']));
        self::assertSame('Coolant and robotics', $created['name']);
        self::assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]], $created['productions']);
        self::assertSame([(string) self::WATER => 'buy', (string) self::ROBOTICS => 'produce'], $created['choices']);
        self::assertSame(['purchaseSource' => 'jita-buy', 'saleSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], $created['settings']);
        self::assertNotSame('', $created['createdAt']);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($created['createdAt']))->getTimestamp(), 120);
        self::assertSame([$created], $listed);
        self::assertSame(1, $this->storedCount($this->alice));
        self::assertSame(0, $this->storedCount($this->bob));
    }

    public function testTheListGoesFromTheMostRecentTemplateToTheOldest(): void
    {
        $this->persistTemplate($this->alice, 'Coolant line', '2026-11-20T09:00:00+00:00');
        $this->persistTemplate($this->alice, 'Robotics', '2026-11-22T18:30:00+00:00');
        $this->persistTemplate($this->alice, 'Broadcast chain', '2026-11-21T12:15:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $names = array_column($this->send('GET'), 'name');

        self::assertSame(['Robotics', 'Broadcast chain', 'Coolant line'], $names);
    }

    public function testTheListOfAUserNeverContainsTheTemplatesOfAnotherUser(): void
    {
        $aliceCoolant = $this->persistTemplate($this->alice, 'Alice coolant', '2026-11-20T09:00:00+00:00');
        $aliceRobotics = $this->persistTemplate($this->alice, 'Alice robotics', '2026-11-21T09:00:00+00:00');
        $bobOnly = $this->persistTemplate($this->bob, 'Bob only', '2026-11-22T09:00:00+00:00');
        $this->em->flush();

        $this->actAs($this->alice);
        $aliceList = array_column($this->send('GET'), 'id');
        $this->actAs($this->bob);
        $bobList = array_column($this->send('GET'), 'id');

        self::assertSame([(string) $aliceRobotics->getId(), (string) $aliceCoolant->getId()], $aliceList);
        self::assertSame([(string) $bobOnly->getId()], $bobList);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    #[DataProvider('refusedBodies')]
    public function testAnInvalidBodyIsRefusedWith422AndNothingIsStored(callable $edit): void
    {
        $this->actAs($this->alice);

        $this->send('POST', $edit($this->body()));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedCount($this->alice));
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function refusedBodies(): iterable
    {
        yield 'an empty name' => [static fn (array $body): array => [...$body, 'name' => '']];
        yield 'a blank name' => [static fn (array $body): array => [...$body, 'name' => '   ']];
        yield 'a name of 101 characters' => [static fn (array $body): array => [...$body, 'name' => str_repeat('a', self::NAME_LIMIT + 1)]];
        yield 'no production' => [static fn (array $body): array => [...$body, 'productions' => []]];
        yield 'twenty-one productions' => [static fn (array $body): array => [...$body, 'productions' => self::productions(21)]];
        yield 'a target of 0' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::COOLANT, 'targetPerDay' => 0]]]];
        yield 'a negative target' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::COOLANT, 'targetPerDay' => -12]]]];
        yield 'a production outside planetary interaction' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::TRITANIUM, 'targetPerDay' => 120]]]];
        yield 'a raw resource as production' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::AQUEOUS_LIQUIDS, 'targetPerDay' => 120]]]];
        yield 'a choice of maybe' => [static fn (array $body): array => [...$body, 'choices' => [self::WATER => 'maybe']]];
        yield 'a choice on a type outside planetary interaction' => [static fn (array $body): array => [...$body, 'choices' => [self::TRITANIUM => 'buy']]];
        yield 'a purchase source that does not exist' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'purchaseSource' => 'jita-mid']]];
        yield 'the best price as sale source' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'saleSource' => 'best']]];
        yield 'a tax of 101' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'taxPercent' => 101]]];
        yield 'a negative tax' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'taxPercent' => -1]]];
        yield 'a delay of 0.4 day' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'daysBetweenRounds' => 0.4]]];
        yield 'no name' => [static function (array $body): array {
            unset($body['name']);

            return $body;
        }];
        yield 'no productions' => [static function (array $body): array {
            unset($body['productions']);

            return $body;
        }];
        yield 'no settings' => [static function (array $body): array {
            unset($body['settings']);

            return $body;
        }];
        yield 'no purchase source' => [static function (array $body): array {
            unset($body['settings']['purchaseSource']);

            return $body;
        }];
        yield 'no sale source' => [static function (array $body): array {
            unset($body['settings']['saleSource']);

            return $body;
        }];
        yield 'no tax' => [static function (array $body): array {
            unset($body['settings']['taxPercent']);

            return $body;
        }];
        yield 'no delay' => [static function (array $body): array {
            unset($body['settings']['daysBetweenRounds']);

            return $body;
        }];
        yield 'a production without target' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::COOLANT]]]];
        yield 'a production without type' => [static fn (array $body): array => [...$body, 'productions' => [['targetPerDay' => 120]]]];
        yield 'an empty body' => [static fn (array $body): array => []];
    }

    #[DataProvider('mistypedBodies')]
    public function testABodyOfTheWrongTypeIsRefusedWithAClientErrorAndNothingIsStored(string $field, mixed $value): void
    {
        $this->actAs($this->alice);

        $this->send('POST', [...$this->body(), $field => $value]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame(0, $this->storedCount($this->alice));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function mistypedBodies(): iterable
    {
        yield 'a name that is a number' => ['name', 5];
        yield 'a name that is a list' => ['name', ['Coolant']];
        yield 'productions that are a string' => ['productions', 'coolant'];
        yield 'choices that are a string' => ['choices', 'buy'];
        yield 'settings that are a string' => ['settings', 'jita-sell'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    #[DataProvider('acceptedBodies')]
    public function testTheValuesOnTheEdgeOfEachRuleAreStored(callable $edit): void
    {
        $this->actAs($this->alice);

        $this->send('POST', $edit($this->body()));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->storedCount($this->alice));
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function acceptedBodies(): iterable
    {
        yield 'a name of 100 characters' => [static fn (array $body): array => [...$body, 'name' => str_repeat('a', self::NAME_LIMIT)]];
        yield 'twenty productions' => [static fn (array $body): array => [...$body, 'productions' => self::productions(20)]];
        yield 'a single production' => [static fn (array $body): array => [...$body, 'productions' => [['typeId' => self::BROADCAST_NODE, 'targetPerDay' => 0.25]]]];
        yield 'a tax of 0' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'taxPercent' => 0]]];
        yield 'a tax of 100' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'taxPercent' => 100]]];
        yield 'half a day between rounds' => [static fn (array $body): array => [...$body, 'settings' => [...$body['settings'], 'daysBetweenRounds' => 0.5]]];
        yield 'no explicit choice' => [static fn (array $body): array => [...$body, 'choices' => []]];
        yield 'a body without choices' => [static function (array $body): array {
            unset($body['choices']);

            return $body;
        }];
    }

    public function testTheFiftiethTemplateIsStoredTheFiftyFirstIsRefusedAndAnotherUserIsNotAffected(): void
    {
        for ($position = 0; $position < self::TEMPLATE_LIMIT - 1; ++$position) {
            $this->persistTemplate($this->alice, sprintf('Alice %02d', $position), sprintf('2026-10-%02dT09:00:00+00:00', $position % 28 + 1));
        }
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('POST', $this->body(['name' => 'Fiftieth']));
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', $this->body(['name' => 'Fifty-first']));
        self::assertResponseStatusCodeSame(422);
        $namesAfterRefusal = array_column($this->send('GET'), 'name');

        self::assertSame(self::TEMPLATE_LIMIT, $this->storedCount($this->alice));
        self::assertContains('Fiftieth', $namesAfterRefusal);
        self::assertNotContains('Fifty-first', $namesAfterRefusal);

        $this->actAs($this->bob);
        $this->send('POST', $this->body(['name' => 'Bob first']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->storedCount($this->bob));
    }

    public function testAUserSentInTheBodyIsIgnoredAndTheTemplateBelongsToTheConnectedUser(): void
    {
        $this->actAs($this->alice);

        $created = $this->send('POST', $this->body([
            'user' => '/api/users/'.$this->bob->getId(),
            'userId' => (string) $this->bob->getId(),
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertTrue(Uuid::isValid($created['id']));
        self::assertSame(1, $this->storedCount($this->alice));
        self::assertSame(0, $this->storedCount($this->bob));
    }

    public function testTwoTemplatesMayShareTheSameName(): void
    {
        $this->actAs($this->alice);

        $first = $this->send('POST', $this->body(['name' => 'Coolant line']));
        self::assertResponseStatusCodeSame(201);
        $second = $this->send('POST', $this->body(['name' => 'Coolant line']));
        self::assertResponseStatusCodeSame(201);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, $this->storedCount($this->alice));
    }

    public function testTheOwnerReadsHisTemplateAndAnotherUserOrAnUnknownIdGetsNotFound(): void
    {
        $own = $this->persistTemplate($this->alice, 'Coolant line', '2026-11-20T09:00:00+00:00');
        $this->em->flush();

        $this->actAs($this->alice);
        $read = $this->send('GET', null, '/'.$own->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Coolant line', $read['name']);
        $this->send('GET', null, '/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);

        $this->actAs($this->bob);
        $this->send('GET', null, '/'.$own->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOwnerDeletesHisTemplateAndTheOthersStay(): void
    {
        $removed = $this->persistTemplate($this->alice, 'To remove', '2026-11-20T09:00:00+00:00');
        $kept = $this->persistTemplate($this->alice, 'To keep', '2026-11-21T09:00:00+00:00');
        $bobs = $this->persistTemplate($this->bob, 'Bob template', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $removedId = (string) $removed->getId();
        $this->actAs($this->alice);

        $this->send('DELETE', null, '/'.$removedId);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([(string) $kept->getId()], array_column($this->send('GET'), 'id'));
        self::assertSame(2, $this->em->getRepository(PlanetaryPlanTemplate::class)->count([]));
        self::assertNull($this->em->getRepository(PlanetaryPlanTemplate::class)->find($removedId));
        self::assertNotNull($this->em->getRepository(PlanetaryPlanTemplate::class)->find((string) $bobs->getId()));
    }

    public function testDeletingATemplateOfAnotherUserAnUnknownOrAMalformedIdIsNotFoundAndRemovesNothing(): void
    {
        $bobs = $this->persistTemplate($this->bob, 'Bob template', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        foreach (['/'.$bobs->getId(), '/'.self::UNKNOWN_ID, '/not-a-uuid'] as $suffix) {
            $this->send('DELETE', null, $suffix);

            self::assertResponseStatusCodeSame(404, $suffix);
        }
        self::assertSame(1, $this->storedCount($this->bob));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<int|string, mixed>
     */
    private function send(string $method, ?array $body = null, string $suffix = ''): array
    {
        if ($this->actingUser !== null) {
            $this->client->getKernel()->boot();
            $this->client->loginUser($this->em->find(User::class, $this->actingUser->getId()), 'api');
        }
        $this->client->request(
            $method,
            self::ENDPOINT.$suffix,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (array) json_decode($content, true);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [...[
            'name' => 'Coolant line',
            'productions' => [['typeId' => self::COOLANT, 'targetPerDay' => 120]],
            'choices' => [self::WATER => 'buy'],
            'settings' => ['purchaseSource' => 'jita-sell', 'saleSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4],
        ], ...$overrides];
    }

    /**
     * @return list<array{typeId: int, targetPerDay: int}>
     */
    private static function productions(int $count): array
    {
        return array_map(static fn (int $position): array => ['typeId' => self::COOLANT, 'targetPerDay' => 10 + $position], range(1, $count));
    }

    private function actAs(User $user): void
    {
        $this->actingUser = $user;
    }

    private function storedCount(User $user): int
    {
        $this->em->clear();

        return $this->em->getRepository(PlanetaryPlanTemplate::class)->count(['user' => $user->getId()]);
    }

    private function persistTemplate(User $user, string $name, string $createdAt): PlanetaryPlanTemplate
    {
        $template = (new PlanetaryPlanTemplate())->setUser($user)->setName($name)->setProductions([['typeId' => self::COOLANT, 'targetPerDay' => 120]]);
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'createdAt'))->setValue($template, new \DateTimeImmutable($createdAt));
        $this->em->persist($template);

        return $template;
    }

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_540_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function seedSchematics(): void
    {
        $outputs = [
            990_100 => [self::WATER, 20, self::AQUEOUS_LIQUIDS, 3_000],
            990_101 => [self::COOLANT, 5, self::WATER, 40],
            990_102 => [self::ROBOTICS, 3, self::COOLANT, 10],
            990_103 => [self::BROADCAST_NODE, 1, self::ROBOTICS, 6],
        ];
        foreach ($outputs as $schematicId => [$outputTypeId, $outputQuantity, $inputTypeId, $inputQuantity]) {
            $schematic = (new PlanetSchematic())->setSchematicId($schematicId)->setSchematicName(sprintf('Test schematic %d', $schematicId))->setCycleTime(3_600);
            $this->em->persist($schematic);
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($inputTypeId)->setIsInput(true)->setQuantity($inputQuantity));
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity($outputQuantity));
        }
        $this->em->flush();
    }
}
