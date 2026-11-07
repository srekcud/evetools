<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-355')]
final class ProjectTemplateApiTest extends WebTestCase
{
    private const string TEMPLATES_URI = '/api/industry/project-templates';
    private const int ALICE_CORPORATION_ID = 98_355_001;
    private const int CAROL_CORPORATION_ID = 98_355_002;
    private const int HAMMERHEAD_II_TYPE_ID = 2185;
    private const int WARRIOR_II_TYPE_ID = 2446;

    private static int $nextEveCharacterId = 2_112_355_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private User $carol;
    private IndustryProject $hammerheadBatch;
    private IndustryProjectTemplate $sharedTemplate;
    private IndustryProjectTemplate $privateTemplate;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->alice = $this->persistPilot('Alice Ferrand', self::ALICE_CORPORATION_ID);
        $this->bob = $this->persistPilot('Bob Lenoir', self::ALICE_CORPORATION_ID);
        $this->carol = $this->persistPilot('Carol Dumas', self::CAROL_CORPORATION_ID);

        $this->hammerheadBatch = (new IndustryProject())
            ->setOwner($this->alice)
            ->setName('Hammerhead batch')
            ->setStatus(ProjectStatus::InProgress)
            ->setBlacklistGroupIds([18])
            ->setBlacklistTypeIds([34])
            ->setAcceptsOutsideFavoriteSystem(true)
            ->setMinimumOutsideSaving(5_000_000.0);
        $this->hammerheadBatch->enrolOwner();
        $this->hammerheadBatch->addItem($this->product(self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 10, 20, 50, 0));
        $this->hammerheadBatch->addItem($this->product(self::WARRIOR_II_TYPE_ID, 'Warrior II', 5, 10, 20, 1));
        $this->em->persist($this->hammerheadBatch);

        $this->sharedTemplate = $this->persistTemplate($this->alice, 'Hammerhead line', true);
        $this->privateTemplate = $this->persistTemplate($this->alice, 'Private Warrior line', false);
        $this->em->flush();
    }

    public function testSavingAProjectAsATemplateCopiesItsItemsBlacklistAndOutsideFavoriteSystemPolicy(): void
    {
        $this->actAs($this->alice);

        $this->request('POST', self::TEMPLATES_URI, [
            'projectId' => $this->hammerheadBatch->getId()->toRfc4122(),
            'name' => 'Hammerhead copy',
            'sharedWithCorporation' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertSame('Hammerhead copy', $created['name']);
        self::assertSame([
            ['typeId' => 2185, 'typeName' => 'Hammerhead II', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 50],
            ['typeId' => 2446, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20],
        ], $created['items']);
        self::assertSame([18], $created['blacklistGroupIds']);
        self::assertSame([34], $created['blacklistTypeIds']);
        self::assertTrue($created['acceptsOutsideFavoriteSystem']);
        self::assertSame(5_000_000.0, (float) $created['minimumOutsideSaving']);
        self::assertFalse($created['sharedWithCorporation']);
        self::assertTrue($created['isOwn']);
        self::assertSame('Alice Ferrand', $created['ownerName']);
        self::assertCount(3, $this->em->getRepository(IndustryProjectTemplate::class)->findBy(['owner' => $this->alice]));
    }

    public function testCorpmateSeesTheSharedTemplateOnlyAndAnotherCorporationSeesNone(): void
    {
        $this->actAs($this->bob);
        $this->request('GET', self::TEMPLATES_URI);
        self::assertResponseStatusCodeSame(200);
        $seenByBob = $this->responseJson();

        $this->actAs($this->carol);
        $this->request('GET', self::TEMPLATES_URI);
        self::assertResponseStatusCodeSame(200);
        $seenByCarol = $this->responseJson();

        self::assertCount(1, $seenByBob);
        self::assertSame((string) $this->sharedTemplate->getId(), $seenByBob[0]['id']);
        self::assertSame('Alice Ferrand', $seenByBob[0]['ownerName']);
        self::assertFalse($seenByBob[0]['isOwn']);
        self::assertTrue($seenByBob[0]['sharedWithCorporation']);
        self::assertSame([], $seenByCarol);
    }

    public function testCorpmateIsForbiddenToPatchTheSharedTemplateAndAnotherCorporationGetsNotFound(): void
    {
        $uri = self::TEMPLATES_URI.'/'.$this->sharedTemplate->getId();

        $this->actAs($this->bob);
        $this->request('PATCH', $uri, ['name' => 'Bob takeover']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Only the template owner can perform this action', $this->responseJson()['detail']);

        $this->actAs($this->carol);
        $this->request('PATCH', $uri, ['sharedWithCorporation' => false]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        $stored = $this->em->find(IndustryProjectTemplate::class, $this->sharedTemplate->getId());
        self::assertSame(['Hammerhead line', true], [$stored?->getName(), $stored?->isSharedWithCorporation()]);
    }

    public function testOwnerDeletingATemplateLeavesTheProjectIntactAndHidesTheTemplateFromTheCorpmate(): void
    {
        $this->actAs($this->alice);
        $this->request('DELETE', self::TEMPLATES_URI.'/'.$this->sharedTemplate->getId());
        self::assertResponseStatusCodeSame(204);

        $this->actAs($this->bob);
        $this->request('GET', self::TEMPLATES_URI);
        $seenByBob = $this->responseJson();

        $this->em->clear();
        self::assertSame([], $seenByBob);
        self::assertNotNull($this->em->find(IndustryProject::class, $this->hammerheadBatch->getId()));
        self::assertNull($this->em->find(IndustryProjectTemplate::class, $this->sharedTemplate->getId()));
    }

    public function testUnauthenticatedRequestIsRefused(): void
    {
        $this->request('GET', self::TEMPLATES_URI);

        self::assertResponseStatusCodeSame(401);
    }

    private function persistPilot(string $name, int $corporationId): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function product(int $typeId, string $typeName, int $meLevel, int $teLevel, int $runs, int $sortOrder): IndustryProjectProduct
    {
        return (new IndustryProjectProduct())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setRuns($runs)
            ->setSortOrder($sortOrder);
    }

    private function persistTemplate(User $owner, string $name, bool $sharedWithCorporation): IndustryProjectTemplate
    {
        $template = (new IndustryProjectTemplate())
            ->setOwner($owner)
            ->setName($name)
            ->setItems([['typeId' => self::WARRIOR_II_TYPE_ID, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20]])
            ->setBlacklistGroupIds([])
            ->setBlacklistTypeIds([])
            ->setSharedWithCorporation($sharedWithCorporation);
        $this->em->persist($template);

        return $template;
    }

    private function actAs(User $user): void
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
