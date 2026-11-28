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

#[Group('issue-1406')]
final class PlanetaryPlanTemplateFollowApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/planetary/templates';
    private const string MERGE_PATCH = 'application/merge-patch+json';
    private const int AQUEOUS_LIQUIDS = 991_010;
    private const int WATER = 991_011;
    private const int COOLANT = 991_021;
    private const int ALICE_EVE_ID = 2_114_060_001;
    private const int BOB_EVE_ID = 2_114_060_002;
    private const string UNKNOWN_ID = '7a1e3c58-d4b9-4f20-8c65-e0a93b2d1f47';

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

    public function testFollowingRequiresAuthenticationAndChangesNothing(): void
    {
        $coolant = $this->persistTemplate($this->alice, 'Coolant line', false);
        $this->em->flush();

        $this->send('PATCH', '/'.$coolant->getId(), ['followed' => true]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->followedNames($this->alice));
    }

    public function testFollowingATemplateReturnsItFollowedAndTheItemAndTheListShowIt(): void
    {
        $coolant = $this->persistTemplate($this->alice, 'Coolant line', false);
        $this->persistTemplate($this->alice, 'Robotics ring', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', '/'.$coolant->getId(), ['followed' => true]);
        self::assertResponseStatusCodeSame(200);
        $item = $this->send('GET', '/'.$coolant->getId());
        $listed = $this->send('GET');

        self::assertSame((string) $coolant->getId(), $patched['id']);
        self::assertSame('Coolant line', $patched['name']);
        self::assertArrayHasKey('followed', $patched);
        self::assertTrue($patched['followed']);
        self::assertTrue($item['followed']);
        $followedByName = array_column($listed, 'followed', 'name');
        self::assertSame(['Coolant line' => true, 'Robotics ring' => false], $followedByName);
        self::assertSame(['Coolant line'], $this->followedNames($this->alice));
    }

    public function testFollowingATemplateStopsFollowingTheOneFollowedBefore(): void
    {
        $first = $this->persistTemplate($this->alice, 'First plan', true);
        $second = $this->persistTemplate($this->alice, 'Second plan', false);
        $third = $this->persistTemplate($this->alice, 'Third plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', '/'.$second->getId(), ['followed' => true]);
        self::assertResponseStatusCodeSame(200);

        self::assertTrue($patched['followed']);
        self::assertSame(['Second plan'], $this->followedNames($this->alice));
        self::assertFalse($this->send('GET', '/'.$first->getId())['followed']);
        self::assertFalse($this->send('GET', '/'.$third->getId())['followed']);
    }

    public function testFollowingNeverTouchesTheTemplatesOfAnotherUser(): void
    {
        $this->persistTemplate($this->alice, 'Alice plan', false);
        $aliceTarget = $this->persistTemplate($this->alice, 'Alice target', false);
        $this->persistTemplate($this->bob, 'Bob followed plan', true);
        $this->persistTemplate($this->bob, 'Bob other plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', '/'.$aliceTarget->getId(), ['followed' => true]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['Alice target'], $this->followedNames($this->alice));
        self::assertSame(['Bob followed plan'], $this->followedNames($this->bob));
    }

    public function testStoppingToFollowTheFollowedTemplateLeavesNoFollowedTemplate(): void
    {
        $followed = $this->persistTemplate($this->alice, 'Followed plan', true);
        $this->persistTemplate($this->alice, 'Other plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', '/'.$followed->getId(), ['followed' => false]);
        self::assertResponseStatusCodeSame(200);

        self::assertArrayHasKey('followed', $patched);
        self::assertFalse($patched['followed']);
        self::assertSame([], $this->followedNames($this->alice));
    }

    public function testStoppingToFollowATemplateThatIsNotFollowedKeepsTheFollowedOneFollowed(): void
    {
        $this->persistTemplate($this->alice, 'Followed plan', true);
        $notFollowed = $this->persistTemplate($this->alice, 'Other plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $patched = $this->send('PATCH', '/'.$notFollowed->getId(), ['followed' => false]);
        self::assertResponseStatusCodeSame(200);

        self::assertFalse($patched['followed']);
        self::assertSame(['Followed plan'], $this->followedNames($this->alice));
    }

    public function testOnlyTheFollowedFieldIsWrittenWhateverElseTheBodyCarries(): void
    {
        $coolant = $this->persistTemplate($this->alice, 'Coolant line', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', '/'.$coolant->getId(), ['followed' => true, 'name' => 'Hijacked', 'productions' => [], 'user' => '/api/users/'.$this->bob->getId()]);
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $stored = $this->em->getRepository(PlanetaryPlanTemplate::class)->find((string) $coolant->getId());
        self::assertSame('Coolant line', $stored->getName());
        self::assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120]], $stored->getProductions());
        self::assertSame((string) $this->alice->getId(), (string) $stored->getUser()->getId());
    }

    #[DataProvider('refusedBodies')]
    public function testABodyWithoutAStrictBooleanFollowedIsRefusedWith422AndNothingChanges(array $body): void
    {
        $this->persistTemplate($this->alice, 'Followed plan', true);
        $other = $this->persistTemplate($this->alice, 'Other plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', '/'.$other->getId(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Followed plan'], $this->followedNames($this->alice));
    }

    public static function refusedBodies(): iterable
    {
        yield 'the string yes' => [['followed' => 'yes']];
        yield 'a body without followed' => [[]];
        yield 'a null followed' => [['followed' => null]];
        yield 'the integer 1' => [['followed' => 1]];
        yield 'the integer 0' => [['followed' => 0]];
    }

    public function testAnIdOfAnotherUserAnUnknownIdOrAMalformedIdIsNotFoundAndNothingChanges(): void
    {
        $bobs = $this->persistTemplate($this->bob, 'Bob plan', false);
        $this->persistTemplate($this->alice, 'Alice plan', true);
        $this->em->flush();
        $this->actAs($this->alice);

        foreach (['/'.$bobs->getId(), '/'.self::UNKNOWN_ID, '/not-a-uuid'] as $suffix) {
            $this->send('PATCH', $suffix, ['followed' => true]);

            self::assertResponseStatusCodeSame(404, $suffix);
        }
        self::assertSame(['Alice plan'], $this->followedNames($this->alice));
        self::assertSame([], $this->followedNames($this->bob));
    }

    public function testABodyWithTheWrongContentTypeIsRefusedWith415AndNothingChanges(): void
    {
        $coolant = $this->persistTemplate($this->alice, 'Coolant line', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('PATCH', '/'.$coolant->getId(), ['followed' => true], 'application/json');

        self::assertResponseStatusCodeSame(415);
        self::assertSame([], $this->followedNames($this->alice));
    }

    public function testATemplateCreatedByPostIsNotFollowedAndTheFollowedOneStaysFollowed(): void
    {
        $this->persistTemplate($this->alice, 'Followed plan', true);
        $this->em->flush();
        $this->actAs($this->alice);

        $created = $this->send('POST', '', [
            'name' => 'Fresh coolant line',
            'productions' => [['typeId' => self::COOLANT, 'targetPerDay' => 120]],
            'choices' => [self::WATER => 'buy'],
            'settings' => ['purchaseSource' => 'jita-sell', 'saleSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4],
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertArrayHasKey('followed', $created);
        self::assertFalse($created['followed']);
        self::assertSame(['Followed plan'], $this->followedNames($this->alice));
    }

    public function testDeletingTheFollowedTemplateLeavesNoFollowedTemplateInTheList(): void
    {
        $followed = $this->persistTemplate($this->alice, 'Followed plan', true);
        $this->persistTemplate($this->alice, 'Other plan', false);
        $this->em->flush();
        $this->actAs($this->alice);

        $this->send('DELETE', '/'.$followed->getId());
        self::assertResponseStatusCodeSame(204);
        $listed = $this->send('GET');

        self::assertSame(['Other plan' => false], array_column($listed, 'followed', 'name'));
    }

    private function send(string $method, string $suffix = '', ?array $body = null, ?string $contentType = null): array
    {
        if ($this->actingUser !== null) {
            $this->client->getKernel()->boot();
            $this->client->loginUser($this->em->find(User::class, $this->actingUser->getId()), 'api');
        }
        $this->client->request(
            $method,
            self::ENDPOINT.$suffix,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType ?? ($method === 'PATCH' ? self::MERGE_PATCH : 'application/json')],
            content: $body === null ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
        );
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : (array) json_decode($content, true);
    }

    private function actAs(User $user): void
    {
        $this->actingUser = $user;
    }

    private function followedNames(User $user): array
    {
        $this->em->clear();
        $templates = $this->em->getRepository(PlanetaryPlanTemplate::class)->findBy(['user' => $user->getId(), 'followed' => true]);
        $names = array_map(static fn (PlanetaryPlanTemplate $template): string => $template->getName(), $templates);
        sort($names);

        return $names;
    }

    private function persistTemplate(User $user, string $name, bool $followed): PlanetaryPlanTemplate
    {
        $template = (new PlanetaryPlanTemplate())
            ->setUser($user)
            ->setName($name)
            ->setProductions([['typeId' => self::COOLANT, 'targetPerDay' => 120]])
            ->setFollowed($followed);
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
            ->setCorporationId(98_540_101)
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
            991_100 => [self::WATER, 20, self::AQUEOUS_LIQUIDS, 3_000],
            991_101 => [self::COOLANT, 5, self::WATER, 40],
        ];
        foreach ($outputs as $schematicId => [$outputTypeId, $outputQuantity, $inputTypeId, $inputQuantity]) {
            $schematic = (new PlanetSchematic())->setSchematicId($schematicId)->setSchematicName(sprintf('Follow schematic %d', $schematicId))->setCycleTime(3_600);
            $this->em->persist($schematic);
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($inputTypeId)->setIsInput(true)->setQuantity($inputQuantity));
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity($outputQuantity));
        }
        $this->em->flush();
    }
}
