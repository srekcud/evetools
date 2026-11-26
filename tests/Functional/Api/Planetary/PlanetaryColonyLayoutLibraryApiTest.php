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

#[Group('issue-933')]
final class PlanetaryColonyLayoutLibraryApiTest extends WebTestCase
{
    private const string LAYOUTS_ENDPOINT = '/api/planetary/layouts';
    private const string LIBRARY_ENDPOINT = '/api/planetary/layout-library';
    private const string MERGE_PATCH = 'application/merge-patch+json';
    private const string UNKNOWN_ID = 'a7d3e915-4c28-4b60-9f1e-83b2c05d6e47';
    private const string AUTHOR_KEY_PATTERN = '/user|owner|author|character|pilot|account/i';
    private const array LIBRARY_ROW_KEYS = ['gameTemplate', 'id', 'mine', 'name', 'publishedAt', 'summary'];

    private const int ALICE_EVE_ID = 2_114_330_001;
    private const int BOB_EVE_ID = 2_114_330_002;
    private const string ALICE_NAME = 'Alice Tarn';
    private const string BOB_NAME = 'Bob Kess';
    private const string BOB_CORPORATION = 'Verhoeven Deep Core';
    private const string BOB_ALLIANCE = 'Gamma Syndicate';
    private const string ALICE_CORPORATION = 'Tarn Logistics';
    private const string ALICE_ALLIANCE = 'Northern Ledger';
    private const int LIBRARY_LIMIT = 200;
    private const int OUTSIDE_LIMIT_COUNT = 201;

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
        $this->alice = $this->persistPilot(self::ALICE_NAME, self::ALICE_EVE_ID, self::ALICE_CORPORATION, self::ALICE_ALLIANCE);
        $this->bob = $this->persistPilot(self::BOB_NAME, self::BOB_EVE_ID, self::BOB_CORPORATION, self::BOB_ALLIANCE);
    }

    public function testTheLibraryRequiresAuthentication(): void
    {
        $this->persistPublicLayout($this->bob, 'Bob public', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();

        $this->client->request('GET', self::LIBRARY_ENDPOINT, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testANewLayoutIsPrivateWithAnExplicitNullPublishedAtAndIsNotInTheLibrary(): void
    {
        $this->actAs($this->alice);

        $created = $this->send('POST', self::LAYOUTS_ENDPOINT, ['template' => $this->templateText(), 'name' => 'Fresh colony']);
        self::assertResponseStatusCodeSame(201);
        $library = $this->send('GET', self::LIBRARY_ENDPOINT);
        self::assertResponseStatusCodeSame(200);

        self::assertArrayHasKey('public', $created);
        self::assertFalse($created['public']);
        self::assertArrayHasKey('publishedAt', $created);
        self::assertNull($created['publishedAt']);
        self::assertSame([], $library);
    }

    public function testTheOwnerPublishesHisLayoutWithAMergePatchAndPublishedAtIsSet(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($patched['public']);
        self::assertIsString($patched['publishedAt']);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($patched['publishedAt']))->getTimestamp(), 120);
        self::assertSame('Coolant colony', $patched['name']);
        self::assertSame($this->normalizedTemplate(), $patched['gameTemplate']);
        $stored = $this->reload($own);
        self::assertTrue($stored->isPublic());
        self::assertSame($patched['publishedAt'], $stored->getPublishedAt()?->format('c'));
    }

    public function testTheOwnerUnpublishesHisLayoutAndPublishedAtIsEmptiedButTheKeyStaysPresent(): void
    {
        $own = $this->persistPublicLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($patched['public']);
        self::assertArrayHasKey('publishedAt', $patched);
        self::assertNull($patched['publishedAt']);
        $stored = $this->reload($own);
        self::assertFalse($stored->isPublic());
        self::assertNull($stored->getPublishedAt());
    }

    public function testRenamingAloneKeepsThePublicationStateOfAPublicAndOfAPrivateLayout(): void
    {
        $public = $this->persistPublicLayout($this->alice, 'Public colony', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $private = $this->persistLayout($this->alice, 'Private colony', '2026-11-20T10:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $renamedPublic = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$public->getId(), ['name' => 'Renamed public']);
        self::assertResponseStatusCodeSame(200);
        $renamedPrivate = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$private->getId(), ['name' => 'Renamed private']);
        self::assertResponseStatusCodeSame(200);

        self::assertTrue($renamedPublic['public']);
        self::assertSame('2026-11-21T09:00:00+00:00', $renamedPublic['publishedAt']);
        self::assertFalse($renamedPrivate['public']);
        self::assertNull($renamedPrivate['publishedAt']);
        self::assertSame('2026-11-21T09:00:00+00:00', $this->reload($public)->getPublishedAt()?->format('c'));
        self::assertFalse($this->reload($private)->isPublic());
    }

    public function testPublishingAndRenamingInTheSameMergePatchAppliesBoth(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['name' => 'Shared colony', 'public' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Shared colony', $patched['name']);
        self::assertTrue($patched['public']);
        self::assertSame(['Shared colony'], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'name'));
    }

    #[DataProvider('mistypedPublicValues')]
    public function testAPublicValueThatIsNotABooleanIsRefusedWith422AndTheLayoutStaysPrivate(mixed $value): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => $value]);

        self::assertResponseStatusCodeSame(422);
        $stored = $this->reload($own);
        self::assertFalse($stored->isPublic());
        self::assertNull($stored->getPublishedAt());
        self::assertSame([], $this->send('GET', self::LIBRARY_ENDPOINT));
    }

    public static function mistypedPublicValues(): iterable
    {
        yield 'string' => ['yes'];
        yield 'integer' => [1];
    }

    public function testAnEmptyMergePatchNeverFailsWith500AndChangesNothing(): void
    {
        $own = $this->persistPublicLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), []);

        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 400, 422]);
        $stored = $this->reload($own);
        self::assertSame('Coolant colony', $stored->getName());
        self::assertTrue($stored->isPublic());
        self::assertSame('2026-11-21T09:00:00+00:00', $stored->getPublishedAt()?->format('c'));
    }

    public function testAnotherUserCannotPublishOrUnpublishALayoutAndTheLayoutIsUnchanged(): void
    {
        $bobsPrivate = $this->persistLayout($this->bob, 'Bob private', '2026-11-20T09:00:00+00:00');
        $bobsPublic = $this->persistPublicLayout($this->bob, 'Bob public', '2026-11-20T10:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$bobsPrivate->getId(), ['public' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$bobsPublic->getId(), ['public' => false]);
        self::assertResponseStatusCodeSame(404);
        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$bobsPublic->getId(), ['name' => 'Hijacked', 'public' => false]);
        self::assertResponseStatusCodeSame(404);

        $privateAfter = $this->reload($bobsPrivate);
        self::assertFalse($privateAfter->isPublic());
        self::assertNull($privateAfter->getPublishedAt());
        $publicAfter = $this->reload($bobsPublic);
        self::assertTrue($publicAfter->isPublic());
        self::assertSame('2026-11-21T09:00:00+00:00', $publicAfter->getPublishedAt()?->format('c'));
        self::assertSame('Bob public', $publicAfter->getName());
    }

    public function testAnotherUserCannotReadOrDeleteAPublicLayoutThroughTheOwnerRoutes(): void
    {
        $bobsPublic = $this->persistPublicLayout($this->bob, 'Bob public', '2026-11-20T10:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $id = (string) $bobsPublic->getId();
        $this->actAs($this->alice);

        $this->send('GET', self::LAYOUTS_ENDPOINT.'/'.$id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->send('GET', self::LAYOUTS_ENDPOINT));
        $this->send('DELETE', self::LAYOUTS_ENDPOINT.'/'.$id);
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->reload($bobsPublic)->isPublic());
    }

    public function testTheLibraryListsMyPublicLayoutsAndThoseOfOthersButNeverAPrivateLayout(): void
    {
        $this->persistPublicLayout($this->alice, 'Alice public', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->persistLayout($this->alice, 'Alice private', '2026-11-20T09:30:00+00:00');
        $this->persistPublicLayout($this->bob, 'Bob public', '2026-11-20T10:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->persistLayout($this->bob, 'Bob private', '2026-11-20T10:30:00+00:00');
        $this->em->flush();

        $this->actAs($this->alice);
        $asAlice = $this->send('GET', self::LIBRARY_ENDPOINT);
        self::assertResponseStatusCodeSame(200);
        $this->actAs($this->bob);
        $asBob = $this->send('GET', self::LIBRARY_ENDPOINT);

        self::assertSame([['Bob public', false], ['Alice public', true]], array_map(static fn (array $row): array => [$row['name'], $row['mine']], $asAlice));
        self::assertSame([['Bob public', true], ['Alice public', false]], array_map(static fn (array $row): array => [$row['name'], $row['mine']], $asBob));
    }

    public function testTheMostRecentlyPublishedLayoutComesFirstWhateverItsCreationDate(): void
    {
        $this->persistPublicLayout($this->bob, 'Created last published first', '2026-11-25T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->persistPublicLayout($this->alice, 'Created first published last', '2026-11-01T09:00:00+00:00', '2026-11-23T09:00:00+00:00');
        $this->persistPublicLayout($this->bob, 'Published in the middle', '2026-11-10T09:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $library = $this->send('GET', self::LIBRARY_ENDPOINT);

        self::assertSame(
            ['Created first published last', 'Published in the middle', 'Created last published first'],
            array_column($library, 'name'),
        );
        self::assertSame(
            ['2026-11-23T09:00:00+00:00', '2026-11-22T09:00:00+00:00', '2026-11-21T09:00:00+00:00'],
            array_column($library, 'publishedAt'),
        );
    }

    public function testTheLibraryReturnsAtMostTwoHundredRowsTheMostRecentlyPublishedOnes(): void
    {
        for ($index = 0; $index < self::OUTSIDE_LIMIT_COUNT; ++$index) {
            $this->persistPublicLayout(
                $index % 2 === 0 ? $this->alice : $this->bob,
                \sprintf('Bulk %03d', $index),
                '2026-10-01T00:00:00+00:00',
                (new \DateTimeImmutable('2026-11-01T00:00:00+00:00'))->modify(\sprintf('+%d minutes', $index))->format('c'),
            );
        }
        $this->em->flush();
        $this->actAs($this->alice);

        $library = $this->send('GET', self::LIBRARY_ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $names = array_column($library, 'name');
        self::assertCount(self::LIBRARY_LIMIT, $library);
        self::assertSame('Bulk 200', $names[0]);
        self::assertSame('Bulk 001', $names[self::LIBRARY_LIMIT - 1]);
        self::assertNotContains('Bulk 000', $names);
    }

    public function testUnpublishingOrDeletingARemovesTheRowAtTheNextCallForEveryone(): void
    {
        $unpublished = $this->persistPublicLayout($this->alice, 'To unpublish', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $deleted = $this->persistPublicLayout($this->alice, 'To delete', '2026-11-20T10:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->persistPublicLayout($this->alice, 'To keep', '2026-11-20T11:00:00+00:00', '2026-11-23T09:00:00+00:00');
        $this->em->flush();
        $unpublishedId = (string) $unpublished->getId();
        $deletedId = (string) $deleted->getId();
        $this->actAs($this->bob);
        self::assertSame(['To keep', 'To delete', 'To unpublish'], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'name'));

        $this->actAs($this->alice);
        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$unpublishedId, ['public' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['To keep', 'To delete'], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'name'));
        $this->send('DELETE', self::LAYOUTS_ENDPOINT.'/'.$deletedId);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['To keep'], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'name'));
        $this->actAs($this->bob);

        self::assertSame(['To keep'], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'name'));
    }

    public function testRepublishingAnUnpublishedLayoutPutsItBackInTheLibrary(): void
    {
        $own = $this->persistLayout($this->alice, 'Coolant colony', '2026-11-20T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => true]);
        $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => false]);
        self::assertSame([], $this->send('GET', self::LIBRARY_ENDPOINT));
        $republished = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertIsString($republished['publishedAt']);
        self::assertSame([(string) $own->getId()], array_column($this->send('GET', self::LIBRARY_ENDPOINT), 'id'));
    }

    public function testALibraryRowExposesExactlyIdNameSummaryGameTemplatePublishedAtAndMine(): void
    {
        $this->persistPublicLayout($this->bob, 'Bob public', '2026-11-20T10:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->persistPublicLayout($this->alice, 'Alice public', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $library = $this->send('GET', self::LIBRARY_ENDPOINT);

        self::assertCount(2, $library);
        foreach ($library as $row) {
            $keys = array_keys($row);
            sort($keys);
            self::assertSame(self::LIBRARY_ROW_KEYS, $keys);
            self::assertIsBool($row['mine']);
        }
    }

    public function testALibraryRowCarriesTheCompleteGameTemplateAndTheSummaryToCopyAndCompare(): void
    {
        $bobs = $this->persistPublicLayout($this->bob, 'Bob coolant', '2026-11-20T10:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $library = $this->send('GET', self::LIBRARY_ENDPOINT);

        self::assertCount(1, $library);
        self::assertSame((string) $bobs->getId(), $library[0]['id']);
        self::assertSame('Bob coolant', $library[0]['name']);
        self::assertSame('2026-11-22T09:00:00+00:00', $library[0]['publishedAt']);
        self::assertSame($this->normalizedTemplate(), $library[0]['gameTemplate']);
        self::assertSame('Gamma Barren', $library[0]['summary']['planetTypeName']);
        self::assertSame(['cpu' => 4320, 'powergrid' => 2020], $library[0]['summary']['load']);
        self::assertSame([['typeId' => self::GAMMA_COOLANT, 'typeName' => 'Gamma Coolant', 'tier' => 'P2']], $library[0]['summary']['products']);
    }

    public function testTheLibraryResponseRevealsNothingAboutTheAuthorOfAnyRow(): void
    {
        $this->persistPublicLayout($this->bob, 'Barren coolant line', '2026-11-20T10:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->persistPublicLayout($this->alice, 'Barren coolant starter', '2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->alice);

        $decoded = $this->send('GET', self::LIBRARY_ENDPOINT);
        $raw = (string) $this->client->getResponse()->getContent();

        self::assertCount(2, $decoded);
        self::assertSame([], $this->authorLikeKeys($decoded));
        $this->assertNoIdentityOf($this->bob, $raw);
        $this->assertNoIdentityOf($this->alice, $raw);
        foreach ([self::BOB_CORPORATION, self::BOB_ALLIANCE, self::BOB_NAME, self::ALICE_CORPORATION, self::ALICE_ALLIANCE, self::ALICE_NAME] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $raw);
        }
    }

    public function testTheResponseOfAPatchOnAPublishedLayoutRevealsNothingAboutItsAuthor(): void
    {
        $own = $this->persistLayout($this->bob, 'Barren coolant line', '2026-11-20T10:00:00+00:00');
        $this->em->flush();
        $this->actAs($this->bob);

        $published = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['public' => true]);
        $publishedRaw = (string) $this->client->getResponse()->getContent();
        $renamed = $this->send('PATCH', self::LAYOUTS_ENDPOINT.'/'.$own->getId(), ['name' => 'Barren coolant renamed']);
        $renamedRaw = (string) $this->client->getResponse()->getContent();

        self::assertTrue($published['public']);
        self::assertSame([], $this->authorLikeKeys($published));
        self::assertSame([], $this->authorLikeKeys($renamed));
        foreach ([$publishedRaw, $renamedRaw] as $raw) {
            $this->assertNoIdentityOf($this->bob, $raw);
            foreach ([self::BOB_CORPORATION, self::BOB_ALLIANCE, self::BOB_NAME] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $raw);
            }
        }
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
    private function send(string $method, string $path, ?array $body = null): array
    {
        if ($this->actingUser !== null) {
            $this->client->getKernel()->boot();
            $this->client->loginUser($this->em->find(User::class, $this->actingUser->getId()), 'api');
        }
        $this->client->request(
            $method,
            $path,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $method === 'PATCH' ? self::MERGE_PATCH : 'application/json'],
            content: $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (array) json_decode($content, true);
    }

    /**
     * @param array<int|string, mixed> $decoded
     *
     * @return list<string>
     */
    private function authorLikeKeys(array $decoded): array
    {
        $offending = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key) && preg_match(self::AUTHOR_KEY_PATTERN, $key) === 1) {
                $offending[] = $key;
            }
            if (\is_array($value)) {
                array_push($offending, ...$this->authorLikeKeys($value));
            }
        }

        return $offending;
    }

    private function assertNoIdentityOf(User $user, string $raw): void
    {
        $eveCharacterId = (string) $user->getMainCharacter()?->getEveCharacterId();
        self::assertNotSame('', $eveCharacterId);
        self::assertStringNotContainsStringIgnoringCase((string) $user->getId(), $raw);
        self::assertStringNotContainsString($eveCharacterId, $raw);
    }

    private function reload(PlanetaryColonyLayout $layout): PlanetaryColonyLayout
    {
        $id = (string) $layout->getId();
        $this->em->clear();
        $stored = $this->em->find(PlanetaryColonyLayout::class, $id);
        self::assertNotNull($stored);

        return $stored;
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

    private function templateText(): string
    {
        return json_encode($this->normalizedTemplate(), \JSON_THROW_ON_ERROR);
    }

    private function persistLayout(User $user, string $name, string $createdAt): PlanetaryColonyLayout
    {
        $layout = (new PlanetaryColonyLayout())->setUser($user)->setName($name)->setTemplate($this->normalizedTemplate());
        (new \ReflectionProperty(PlanetaryColonyLayout::class, 'createdAt'))->setValue($layout, new \DateTimeImmutable($createdAt));
        $this->em->persist($layout);

        return $layout;
    }

    private function persistPublicLayout(User $user, string $name, string $createdAt, string $publishedAt): PlanetaryColonyLayout
    {
        $layout = $this->persistLayout($user, $name, $createdAt);
        $layout->publish(new \DateTimeImmutable($publishedAt));

        return $layout;
    }

    private function persistPilot(string $name, int $eveCharacterId, string $corporationName, string $allianceName): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_540_001)
            ->setCorporationName($corporationName)
            ->setAllianceId(99_540_001)
            ->setAllianceName($allianceName);
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
