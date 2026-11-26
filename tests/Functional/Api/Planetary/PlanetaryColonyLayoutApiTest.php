<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColonyLayout;
use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-933')]
final class PlanetaryColonyLayoutApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/planetary/layouts';
    private const string MERGE_PATCH = 'application/merge-patch+json';
    private const string UNKNOWN_ID = 'a7d3e915-4c28-4b60-9f1e-83b2c05d6e47';

    private const int ALICE_EVE_ID = 2_114_330_001;
    private const int BOB_EVE_ID = 2_114_330_002;
    private const int LAYOUT_LIMIT = 100;
    private const int NAME_LIMIT = 100;
    private const int TEMPLATE_BYTE_LIMIT = 65_536;
    private const int OUTSIDE_INT4 = 99_999_999_999;

    private const int CATEGORY_ID = 991_900;
    private const int PLANET_GROUP = 7;
    private const int PROCESSOR_GROUP = 1028;
    private const int LAUNCHPAD_GROUP = 1030;
    private const int RAW_RESOURCE_GROUP = 1032;
    private const int BASIC_COMMODITY_GROUP = 1033;
    private const int REFINED_COMMODITY_GROUP = 1034;
    private const int BASIC_COMMODITIES_MARKET_GROUP = 1334;
    private const int REFINED_COMMODITIES_MARKET_GROUP = 1335;

    private const int CPU_LOAD = 49;
    private const int POWER_LOAD = 15;

    private const int GAMMA_PLANET = 991_016;
    private const int GAMMA_LAUNCHPAD = 991_544;
    private const int GAMMA_FACILITY = 991_474;
    private const int GAMMA_AQUEOUS_LIQUIDS = 991_268;
    private const int GAMMA_WATER = 991_645;
    private const int GAMMA_COOLANT = 991_832;
    private const int COOLANT_SCHEMATIC_ID = 991_121;

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
        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
    }

    public function testEveryOperationRequiresAuthentication(): void
    {
        $existing = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $id = (string) $existing->getId();

        foreach ([
            ['GET', self::ENDPOINT],
            ['POST', self::ENDPOINT],
            ['GET', self::ENDPOINT.'/'.$id],
            ['PATCH', self::ENDPOINT.'/'.$id],
            ['DELETE', self::ENDPOINT.'/'.$id],
        ] as [$method, $uri]) {
            $this->client->request(
                $method,
                $uri,
                server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $method === 'PATCH' ? self::MERGE_PATCH : 'application/json'],
                content: \in_array($method, ['POST', 'PATCH'], true) ? json_encode(['template' => $this->templateText(), 'name' => 'Renamed'], \JSON_THROW_ON_ERROR) : null,
            );

            self::assertResponseStatusCodeSame(401, \sprintf('%s %s', $method, $uri));
        }
        self::assertSame(['Coolant colony'], $this->storedNames($this->alice));
    }

    public function testAValidPostCreatesTheLayoutWithItsSummaryAndTheNormalizedGameTemplate(): void
    {
        $this->actAs($this->alice);

        $created = $this->send('POST', ['template' => $this->templateText(), 'name' => 'My coolant colony']);
        self::assertResponseStatusCodeSame(201);
        $listed = $this->send('GET');
        self::assertResponseStatusCodeSame(200);

        self::assertTrue(Uuid::isValid($created['id']));
        self::assertSame('My coolant colony', $created['name']);
        self::assertSame($this->normalizedTemplate(), $created['gameTemplate']);
        self::assertSame([
            'planetTypeId' => self::GAMMA_PLANET,
            'planetTypeName' => 'Gamma Barren',
            'commandCenterLevel' => 5,
            'buildings' => ['extractorControlUnits' => 0, 'extractorHeads' => 0, 'processors' => 2, 'storages' => 0, 'launchpads' => 1],
            'links' => 2,
            'routes' => 2,
            'load' => ['cpu' => 4320, 'powergrid' => 2020],
            'products' => [['typeId' => self::GAMMA_COOLANT, 'typeName' => 'Gamma Coolant', 'tier' => 'P2']],
            'extracted' => [],
            'skills' => [['name' => 'Command Center Upgrades', 'level' => 5]],
        ], $created['summary']);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($created['createdAt']))->getTimestamp(), 120);
        self::assertSame([$created], $listed);
        self::assertSame(['My coolant colony'], $this->storedNames($this->alice));
        self::assertSame([], $this->storedNames($this->bob));
    }

    public function testTheNameDefaultsToTheGameCommentAndIsTrimmed(): void
    {
        $this->actAs($this->alice);

        $fromComment = $this->send('POST', ['template' => $this->templateText()]);
        self::assertResponseStatusCodeSame(201);
        $trimmed = $this->send('POST', ['template' => $this->templateText(), 'name' => '  Spaced name  ']);
        self::assertResponseStatusCodeSame(201);

        self::assertSame('Coolant starter', $fromComment['name']);
        self::assertSame('Spaced name', $trimmed['name']);
    }

    public function testTheSameTextImportedTwiceMakesTwoLayouts(): void
    {
        $this->actAs($this->alice);

        $first = $this->send('POST', ['template' => $this->templateText(), 'name' => 'Twin']);
        self::assertResponseStatusCodeSame(201);
        $second = $this->send('POST', ['template' => $this->templateText(), 'name' => 'Twin']);
        self::assertResponseStatusCodeSame(201);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(['Twin', 'Twin'], $this->storedNames($this->alice));
    }

    public function testAnInvalidTemplateIsRefusedWith422ListingEveryErrorAndNothingIsStored(): void
    {
        $this->actAs($this->alice);
        $template = $this->templateArray();
        $template['CmdCtrLv'] = 6;
        $template['P'][0]['T'] = 991_999;
        $template['P'][2]['S'] = self::GAMMA_WATER;

        $body = $this->send('POST', ['template' => json_encode($template, \JSON_THROW_ON_ERROR)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([
            ['CmdCtrLv', 'out_of_range'],
            ['P[0].T', 'unknown_installation'],
            ['P[2].S', 'unknown_product'],
        ], $this->sortedViolations($body));
        self::assertSame([], $this->storedNames($this->alice));
    }

    public function testATextThatIsNotJsonIsRefusedAsInvalidJson(): void
    {
        $this->actAs($this->alice);

        $body = $this->send('POST', ['template' => 'not a colony layout']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['$', 'invalid_json']], $this->sortedViolations($body));
        self::assertSame([], $this->storedNames($this->alice));
    }

    public function testATemplateOverTheByteLimitIsRefusedAsTooLarge(): void
    {
        $this->actAs($this->alice);
        $template = $this->templateArray();
        $template['Cmt'] = str_repeat('a', self::TEMPLATE_BYTE_LIMIT);

        $body = $this->send('POST', ['template' => json_encode($template, \JSON_THROW_ON_ERROR), 'name' => 'Huge']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['$', 'too_large']], $this->sortedViolations($body));
        self::assertSame([], $this->storedNames($this->alice));
    }

    public function testTypeIdsOutsideInt4AreRefusedWith422AndNeverFailWith500(): void
    {
        $this->actAs($this->alice);
        $template = $this->templateArray();
        $template['Pln'] = self::OUTSIDE_INT4;
        $template['P'][0]['T'] = self::OUTSIDE_INT4;
        $template['P'][1]['S'] = self::OUTSIDE_INT4;

        $body = $this->send('POST', ['template' => json_encode($template, \JSON_THROW_ON_ERROR)]);

        self::assertResponseStatusCodeSame(422);
        $violations = $this->sortedViolations($body);
        self::assertContains(['Pln', 'unknown_planet'], $violations);
        self::assertContains(['P[0].T', 'unknown_installation'], $violations);
        self::assertContains(['P[1].S', 'unknown_product'], $violations);
        self::assertSame([], $this->storedNames($this->alice));
    }

    public function testABodyWithoutTemplateOrWithANullTemplateIsRefusedWith422(): void
    {
        $this->actAs($this->alice);

        foreach ([['name' => 'No template'], ['template' => null, 'name' => 'Null template'], []] as $body) {
            $this->send('POST', $body);

            self::assertResponseStatusCodeSame(422, json_encode($body, \JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->storedNames($this->alice));
    }

    #[DataProvider('mistypedBodies')]
    public function testABodyOfTheWrongTypeIsRefusedWithAClientErrorAndNothingIsStored(string $field, mixed $value): void
    {
        $this->actAs($this->alice);

        $this->send('POST', [...['template' => $this->templateText(), 'name' => 'Typed'], $field => $value]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame([], $this->storedNames($this->alice));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function mistypedBodies(): iterable
    {
        yield 'a template that is a number' => ['template', 5];
        yield 'a template that is an object' => ['template', ['CmdCtrLv' => 5]];
        yield 'a name that is a number' => ['name', 5];
        yield 'a name that is a list' => ['name', ['Coolant']];
    }

    public function testABlankNameIsRefusedAsNameBlankWhetherEnteredOrReadFromTheComment(): void
    {
        $this->actAs($this->alice);
        $withoutComment = $this->templateArray();
        $withoutComment['Cmt'] = '';

        $enteredBlank = $this->send('POST', ['template' => $this->templateText(), 'name' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $commentBlank = $this->send('POST', ['template' => json_encode($withoutComment, \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([['name', 'name_blank']], $this->sortedViolations($enteredBlank));
        self::assertSame([['name', 'name_blank']], $this->sortedViolations($commentBlank));
        self::assertSame([], $this->storedNames($this->alice));
    }

    public function testANameOfOneHundredCharactersIsStoredAndOneHundredAndOneIsRefused(): void
    {
        $this->actAs($this->alice);

        $this->send('POST', ['template' => $this->templateText(), 'name' => str_repeat('b', self::NAME_LIMIT + 1)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedNames($this->alice));

        $created = $this->send('POST', ['template' => $this->templateText(), 'name' => str_repeat('b', self::NAME_LIMIT)]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('b', self::NAME_LIMIT), $created['name']);
    }

    public function testTheHundredthLayoutIsStoredTheHundredAndFirstIsRefusedAndAnotherUserIsNotAffected(): void
    {
        for ($position = 0; $position < self::LAYOUT_LIMIT - 1; ++$position) {
            $this->persistLayout($this->alice, \sprintf('Alice %03d', $position), \sprintf('2026-10-%02dT09:00:00+00:00', $position % 28 + 1));
        }
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('POST', ['template' => $this->templateText(), 'name' => 'Hundredth']);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', ['template' => $this->templateText(), 'name' => 'Hundred and first']);
        self::assertResponseStatusCodeSame(422);

        $names = $this->storedNames($this->alice);
        self::assertCount(self::LAYOUT_LIMIT, $names);
        self::assertContains('Hundredth', $names);
        self::assertNotContains('Hundred and first', $names);

        $this->actAs($this->bob);
        $this->send('POST', ['template' => $this->templateText(), 'name' => 'Bob first']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Bob first'], $this->storedNames($this->bob));
    }

    public function testAUserSentInTheBodyIsIgnoredAndTheLayoutBelongsToTheConnectedUser(): void
    {
        $this->actAs($this->alice);

        $created = $this->send('POST', [
            'template' => $this->templateText(),
            'name' => 'Mine',
            'user' => '/api/users/'.$this->bob->getId(),
            'userId' => (string) $this->bob->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue(Uuid::isValid($created['id']));
        self::assertSame(['Mine'], $this->storedNames($this->alice));
        self::assertSame([], $this->storedNames($this->bob));
    }

    public function testTheListOfAUserNeverContainsTheLayoutsOfAnotherUser(): void
    {
        $aliceOlder = $this->persistLayout($this->alice, 'Alice older', '2026-11-20T09:00:00+00:00');
        $aliceNewer = $this->persistLayout($this->alice, 'Alice newer', '2026-11-21T09:00:00+00:00');
        $bobOnly = $this->persistLayout($this->bob, 'Bob only', '2026-11-22T09:00:00+00:00');
        $this->em->flush();

        $this->actAs($this->alice);
        $aliceList = array_column($this->send('GET'), 'id');
        $this->actAs($this->bob);
        $bobList = array_column($this->send('GET'), 'id');

        self::assertSame([(string) $aliceNewer->getId(), (string) $aliceOlder->getId()], $aliceList);
        self::assertSame([(string) $bobOnly->getId()], $bobList);
    }

    public function testTheOwnerReadsHisLayoutAndAnotherUserOrAnUnknownIdGetsNotFound(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();

        $this->actAs($this->alice);
        $read = $this->send('GET', null, '/'.$own->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Coolant colony', $read['name']);
        self::assertSame($this->normalizedTemplate(), $read['gameTemplate']);
        self::assertSame(2, $read['summary']['links']);
        $this->send('GET', null, '/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);

        $this->actAs($this->bob);
        $this->send('GET', null, '/'.$own->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOwnerRenamesHisLayoutWithAMergePatchAndTheTemplateStaysTheSame(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', ['name' => '  Renamed colony  '], '/'.$own->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Renamed colony', $patched['name']);
        self::assertSame($this->normalizedTemplate(), $patched['gameTemplate']);
        self::assertSame(2, $patched['summary']['routes']);
        self::assertSame(['Renamed colony'], $this->storedNames($this->alice));
    }

    public function testAnInvalidNameOnPatchIsRefusedAndTheLayoutIsIntact(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $blank = $this->send('PATCH', ['name' => '   '], '/'.$own->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['name', 'name_blank']], $this->sortedViolations($blank));
        $this->send('PATCH', ['name' => str_repeat('c', self::NAME_LIMIT + 1)], '/'.$own->getId());
        self::assertResponseStatusCodeSame(422);
        $this->send('PATCH', ['name' => null], '/'.$own->getId());
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422]);

        self::assertSame(['Coolant colony'], $this->storedNames($this->alice));
    }

    public function testPatchingALayoutOfAnotherUserOrAnUnknownIdIsNotFoundAndChangesNothing(): void
    {
        $bobs = $this->persistLayout($this->bob, 'Bob layout', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        foreach (['/'.$bobs->getId(), '/'.self::UNKNOWN_ID, '/not-a-uuid'] as $suffix) {
            $this->send('PATCH', ['name' => 'Hijacked'], $suffix);

            self::assertResponseStatusCodeSame(404, $suffix);
        }
        self::assertSame(['Bob layout'], $this->storedNames($this->bob));
    }

    public function testTheOwnerDeletesHisLayoutThenItIsNotFoundAndTheOthersStay(): void
    {
        $removed = $this->persistLayout($this->alice, 'To remove', '2026-11-20T09:00:00+00:00');
        $kept = $this->persistLayout($this->alice, 'To keep', '2026-11-21T09:00:00+00:00');
        $bobs = $this->persistLayout($this->bob, 'Bob layout', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $removedId = (string) $removed->getId();
        $keptId = (string) $kept->getId();
        $bobsId = (string) $bobs->getId();
        $this->actAs($this->alice);

        $this->send('DELETE', null, '/'.$removedId);
        self::assertResponseStatusCodeSame(204);
        $this->send('GET', null, '/'.$removedId);
        self::assertResponseStatusCodeSame(404);
        $this->send('DELETE', null, '/'.$removedId);
        self::assertResponseStatusCodeSame(404);

        self::assertSame([$keptId], array_column($this->send('GET'), 'id'));
        self::assertSame(2, $this->em->getRepository(PlanetaryColonyLayout::class)->count([]));
        self::assertNull($this->em->getRepository(PlanetaryColonyLayout::class)->find($removedId));
        self::assertNotNull($this->em->getRepository(PlanetaryColonyLayout::class)->find($bobsId));
    }

    public function testDeletingALayoutOfAnotherUserAnUnknownOrAMalformedIdIsNotFoundAndRemovesNothing(): void
    {
        $bobs = $this->persistLayout($this->bob, 'Bob layout', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        foreach (['/'.$bobs->getId(), '/'.self::UNKNOWN_ID, '/not-a-uuid'] as $suffix) {
            $this->send('DELETE', null, $suffix);

            self::assertResponseStatusCodeSame(404, $suffix);
        }
        self::assertSame(['Bob layout'], $this->storedNames($this->bob));
    }

    private function actAs(User $user): void
    {
        $this->actingUser = $user;
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
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $method === 'PATCH' ? self::MERGE_PATCH : 'application/json'],
            content: $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (array) json_decode($content, true);
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return list<array{string, string}>
     */
    private function sortedViolations(array $body): array
    {
        self::assertArrayHasKey('violations', $body);
        $violations = array_map(
            static fn (array $violation): array => [(string) $violation['propertyPath'], (string) $violation['message']],
            $body['violations'],
        );
        sort($violations);

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateArray(): array
    {
        return [
            'Zoom' => 3,
            'R' => [
                ['T' => self::GAMMA_WATER, 'Q' => 40, 'P' => [2, 1], 'Flow' => 'fast'],
                ['P' => [3, 1], 'Q' => 40, 'T' => self::GAMMA_WATER],
            ],
            'Pln' => self::GAMMA_PLANET,
            'P' => [
                ['T' => self::GAMMA_LAUNCHPAD, 'S' => null, 'La' => 1.25, 'Lo' => -0.5, 'H' => 0, 'Uid' => 77],
                ['T' => self::GAMMA_FACILITY, 'S' => self::GAMMA_COOLANT, 'La' => 1.5, 'Lo' => -0.25, 'H' => 0],
                ['S' => self::GAMMA_COOLANT, 'T' => self::GAMMA_FACILITY, 'Lo' => 0.25, 'La' => 1.75, 'H' => 0],
            ],
            'L' => [
                ['S' => 1, 'D' => 2, 'Lv' => 0, 'Width' => 4],
                ['D' => 3, 'S' => 1, 'Lv' => 0],
            ],
            'Diam' => 0.5,
            'Cmt' => 'Coolant starter',
            'CmdCtrLv' => 5,
        ];
    }

    private function templateText(): string
    {
        return json_encode($this->templateArray(), \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedTemplate(): array
    {
        return [
            'CmdCtrLv' => 5,
            'Cmt' => 'Coolant starter',
            'Diam' => 0.5,
            'L' => [
                ['D' => 2, 'Lv' => 0, 'S' => 1],
                ['D' => 3, 'Lv' => 0, 'S' => 1],
            ],
            'P' => [
                ['H' => 0, 'La' => 1.25, 'Lo' => -0.5, 'S' => null, 'T' => self::GAMMA_LAUNCHPAD],
                ['H' => 0, 'La' => 1.5, 'Lo' => -0.25, 'S' => self::GAMMA_COOLANT, 'T' => self::GAMMA_FACILITY],
                ['H' => 0, 'La' => 1.75, 'Lo' => 0.25, 'S' => self::GAMMA_COOLANT, 'T' => self::GAMMA_FACILITY],
            ],
            'Pln' => self::GAMMA_PLANET,
            'R' => [
                ['P' => [2, 1], 'Q' => 40, 'T' => self::GAMMA_WATER],
                ['P' => [3, 1], 'Q' => 40, 'T' => self::GAMMA_WATER],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function storedNames(User $user): array
    {
        $this->em->clear();
        $names = array_map(
            static fn (PlanetaryColonyLayout $layout): string => $layout->getName(),
            $this->em->getRepository(PlanetaryColonyLayout::class)->findBy(['user' => $user->getId()]),
        );
        sort($names);

        return $names;
    }

    private function persistLayout(User $user, string $name, string $createdAt): PlanetaryColonyLayout
    {
        $layout = (new PlanetaryColonyLayout())->setUser($user)->setName($name)->setTemplate($this->normalizedTemplate());
        (new \ReflectionProperty(PlanetaryColonyLayout::class, 'createdAt'))->setValue($layout, new \DateTimeImmutable($createdAt));
        $this->em->persist($layout);

        return $layout;
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

    private function seedSde(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Gamma Planetary');
        $this->em->persist($category);
        $groups = [];
        foreach ([
            self::PLANET_GROUP => 'Planet',
            self::PROCESSOR_GROUP => 'Processors',
            self::LAUNCHPAD_GROUP => 'Spaceports',
            self::RAW_RESOURCE_GROUP => 'Planet Resources',
            self::BASIC_COMMODITY_GROUP => 'Basic Commodities',
            self::REFINED_COMMODITY_GROUP => 'Refined Commodities',
        ] as $groupId => $groupName) {
            $group = $this->em->find(InvGroup::class, $groupId);
            if ($group === null) {
                $group = (new InvGroup())->setGroupId($groupId)->setGroupName($groupName)->setCategory($category);
                $this->em->persist($group);
            }
            $groups[$groupId] = $group;
        }
        $marketGroups = [];
        foreach ([
            self::BASIC_COMMODITIES_MARKET_GROUP => 'Basic Commodities',
            self::REFINED_COMMODITIES_MARKET_GROUP => 'Refined Commodities',
        ] as $marketGroupId => $marketGroupName) {
            $marketGroup = $this->em->find(InvMarketGroup::class, $marketGroupId);
            if ($marketGroup === null) {
                $marketGroup = (new InvMarketGroup())->setMarketGroupId($marketGroupId)->setMarketGroupName($marketGroupName);
                $this->em->persist($marketGroup);
            }
            $marketGroups[$marketGroupId] = $marketGroup;
        }

        $types = [
            [self::GAMMA_PLANET, 'Gamma Barren', self::PLANET_GROUP, null, []],
            [self::GAMMA_LAUNCHPAD, 'Gamma Launchpad', self::LAUNCHPAD_GROUP, null, [self::CPU_LOAD => 3300, self::POWER_LOAD => 640]],
            [self::GAMMA_FACILITY, 'Gamma Advanced Industry Facility', self::PROCESSOR_GROUP, null, [self::CPU_LOAD => 510, self::POWER_LOAD => 690]],
            [self::GAMMA_AQUEOUS_LIQUIDS, 'Gamma Aqueous Liquids', self::RAW_RESOURCE_GROUP, null, []],
            [self::GAMMA_WATER, 'Gamma Water', self::BASIC_COMMODITY_GROUP, self::BASIC_COMMODITIES_MARKET_GROUP, []],
            [self::GAMMA_COOLANT, 'Gamma Coolant', self::REFINED_COMMODITY_GROUP, self::REFINED_COMMODITIES_MARKET_GROUP, []],
        ];
        foreach ($types as [$typeId, $name, $groupId, $marketGroupId, $attributes]) {
            $type = (new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($groups[$groupId]);
            if ($marketGroupId !== null) {
                $type->setMarketGroup($marketGroups[$marketGroupId]);
            }
            $this->em->persist($type);
            foreach ($attributes as $attributeId => $value) {
                $this->em->persist((new DgmTypeAttribute())->setTypeId($typeId)->setAttributeId($attributeId)->setValueInt($value));
            }
        }

        $schematic = (new PlanetSchematic())->setSchematicId(self::COOLANT_SCHEMATIC_ID)->setSchematicName('Gamma Coolant')->setCycleTime(1800);
        $this->em->persist($schematic);
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::GAMMA_WATER)->setIsInput(true)->setQuantity(40));
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::GAMMA_COOLANT)->setIsInput(false)->setQuantity(5));
        $this->em->flush();
    }
}
