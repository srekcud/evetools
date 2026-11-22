<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Me;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1249')]
final class CharacterActiveProjectApiTest extends WebTestCase
{
    private const int CORPORATION_ID = 98_249_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $nextEveCharacterId = 2_112_249_001;
    private User $caller;
    private Character $character;
    private ?User $actingAs = null;
    private User $stranger;
    private Character $strangerCharacter;
    private IndustryProject $orcaBatch;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->caller = $this->persistUserWithCharacter('Aria Solberg');
        $this->character = $this->caller->getMainCharacter();
        $this->stranger = $this->persistUserWithCharacter('Kel Dorn');
        $this->strangerCharacter = $this->stranger->getMainCharacter();
        $this->orcaBatch = $this->persistProject('Orca batch', $this->caller, ProjectStatus::InProgress);
        $this->em->flush();
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->patch($this->character, ['projectId' => $this->orcaBatch->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDedicatingACharacterReturnsTheProjectAndTheCharacterListShowsIt(): void
    {
        $this->actingAs = $this->caller;

        $this->patch($this->character, ['projectId' => $this->orcaBatch->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertSame($this->character->getId()->toRfc4122(), $resource['id']);
        self::assertSame($this->orcaBatch->getId()->toRfc4122(), $resource['activeProjectId']);
        self::assertSame('Orca batch', $resource['activeProjectName']);

        $entry = $this->characterListEntryOf($this->character);
        self::assertSame($this->orcaBatch->getId()->toRfc4122(), $entry['activeProjectId']);
        self::assertSame('Orca batch', $entry['activeProjectName']);
    }

    public function testANullProjectIdClearsTheActiveProjectAndTheKeysStayPresentAsNull(): void
    {
        $this->character->setActiveProject($this->orcaBatch);
        $this->em->flush();
        $this->actingAs = $this->caller;

        $this->patch($this->character, ['projectId' => null]);

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('activeProjectId', $resource);
        self::assertNull($resource['activeProjectId']);
        self::assertArrayHasKey('activeProjectName', $resource);
        self::assertNull($resource['activeProjectName']);

        $entry = $this->characterListEntryOf($this->character);
        self::assertArrayHasKey('activeProjectId', $entry);
        self::assertNull($entry['activeProjectId']);
        self::assertArrayHasKey('activeProjectName', $entry);
        self::assertNull($entry['activeProjectName']);
    }

    public function testACharacterWithoutActiveProjectIsListedWithNullIdAndName(): void
    {
        $this->actingAs = $this->caller;

        $entry = $this->characterListEntryOf($this->character);

        self::assertArrayHasKey('activeProjectId', $entry);
        self::assertNull($entry['activeProjectId']);
        self::assertArrayHasKey('activeProjectName', $entry);
        self::assertNull($entry['activeProjectName']);
    }

    public function testACharacterOfAnotherUserIsNotFoundAndKeepsItsActiveProject(): void
    {
        $this->actingAs = $this->caller;

        $this->patch($this->strangerCharacter, ['projectId' => $this->orcaBatch->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(404);
        $this->em->refresh($this->strangerCharacter);
        self::assertNull($this->strangerCharacter->getActiveProject());
    }

    public function testTheProjectOfAUserTheCallerDoesNotShareIsNotFoundAndKeepsTheActiveProject(): void
    {
        $foreign = $this->persistProject('Foreign batch', $this->stranger, ProjectStatus::InProgress);
        $this->character->setActiveProject($this->orcaBatch);
        $this->em->flush();
        $this->actingAs = $this->caller;

        $this->patch($this->character, ['projectId' => $foreign->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(404);
        $this->em->refresh($this->character);
        self::assertSame($this->orcaBatch->getId()->toRfc4122(), $this->character->getActiveProject()?->getId()->toRfc4122());
    }

    public function testAProjectIdThatIsNotAUuidIsNotFound(): void
    {
        $this->actingAs = $this->caller;

        $this->patch($this->character, ['projectId' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testABodyWithoutTheProjectIdKeyIsABadRequestAndKeepsTheActiveProject(): void
    {
        $this->character->setActiveProject($this->orcaBatch);
        $this->em->flush();
        $this->actingAs = $this->caller;

        $this->patch($this->character, []);

        self::assertResponseStatusCodeSame(400);
        $this->em->refresh($this->character);
        self::assertSame($this->orcaBatch->getId()->toRfc4122(), $this->character->getActiveProject()?->getId()->toRfc4122());
    }

    public function testCompletingTheProjectClearsTheActiveProjectOfTheCharactersDedicatedToIt(): void
    {
        $selling = $this->persistProject('Selling batch', $this->caller, ProjectStatus::Selling);
        $this->character->setActiveProject($selling);
        $this->em->flush();
        $this->actingAs = $this->caller;

        $this->request('PATCH', '/api/industry/projects/'.$selling->getId()->toRfc4122(), ['status' => 'completed']);

        self::assertResponseStatusCodeSame(200);
        $entry = $this->characterListEntryOf($this->character);
        self::assertArrayHasKey('activeProjectId', $entry);
        self::assertNull($entry['activeProjectId']);
        self::assertArrayHasKey('activeProjectName', $entry);
        self::assertNull($entry['activeProjectName']);
    }

    private function patch(Character $character, array $payload): void
    {
        $this->request('PATCH', '/api/me/characters/'.$character->getId()->toRfc4122().'/active-project', $payload);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        if ($this->actingAs !== null) {
            $this->client->getKernel()->boot();
            $this->client->loginUser($this->em->find(User::class, $this->actingAs->getId()), 'api');
        }

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

    private function characterListEntryOf(Character $character): array
    {
        $this->request('GET', '/api/me/characters');
        self::assertResponseIsSuccessful();

        $entries = array_values(array_filter(
            $this->responseJson(),
            static fn (array $entry): bool => $entry['id'] === $character->getId()->toRfc4122(),
        ));
        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function persistUserWithCharacter(string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Active Project Test Corp');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function persistProject(string $name, User $owner, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $project->addMember((new IndustryProjectParticipant())->setUser($owner)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted));
        $this->em->persist($project);

        return $project;
    }
}
