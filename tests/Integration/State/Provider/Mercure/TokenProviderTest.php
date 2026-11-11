<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Mercure;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Mercure\TokenProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-362')]
final class TokenProviderTest extends IntegrationTestCase
{
    public function testSubscriberTopicsIncludeTheEventsOfTheProjectWithItems(): void
    {
        $owner = $this->createUser();
        $owner->setMainCharacter($this->createCharacter($owner, 'Kaelen Voss')->setCorporationId(98_362_201));
        $groupProject = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Sabre by items')
            ->setStatus(ProjectStatus::Published)
            ->addItem(
                (new IndustryProjectProduct())
                    ->setTypeId(22456)
                    ->setTypeName('Sabre')
                    ->setMeLevel(10)
                    ->setTeLevel(20)
                    ->setRuns(5),
            );
        $groupProject->addMember($this->ownerParticipant($owner));
        $this->em->persist($groupProject);
        $this->em->flush();
        $ownerId = (string) $owner->getId();
        $groupProjectId = (string) $groupProject->getId();
        $this->em->clear();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $ownerId));
        $provider = $this->tokenProvider($security);

        $topics = $provider->provide(new Get(), [], ['request' => $this->requestToTheHub()])->topics;

        self::assertContains(sprintf('/group-project/%s/events', $groupProjectId), $topics);
        self::assertCount(1, array_filter($topics, static fn (string $topic): bool => str_starts_with($topic, '/group-project/')));
    }

    public function testSubscriberTopicsIncludeTheEventsOfEveryProjectWhereTheUserIsAcceptedSoloProjectIncludedAndNoOther(): void
    {
        $owner = $this->createUser();
        $owner->setMainCharacter($this->createCharacter($owner, 'Aria Solberg')->setCorporationId(98_362_202));
        $otherOwner = $this->createUser();
        $otherOwner->setMainCharacter($this->createCharacter($otherOwner, 'Brann Holt')->setCorporationId(98_362_202));

        $soloProject = $this->projectOf($owner, 'Solo Rifter', ProjectStatus::Draft);
        $soloProject->addMember($this->ownerParticipant($owner));
        $groupProject = $this->projectOf($owner, 'Group Sabre', ProjectStatus::InProgress)->addItem(
            (new IndustryProjectProduct())->setTypeId(22456)->setTypeName('Sabre')->setMeLevel(10)->setTeLevel(20)->setRuns(5),
        );
        $groupProject->addMember($this->ownerParticipant($owner));
        $groupProject->addMember($this->participantOf($otherOwner, ParticipantRole::Member, ParticipantStatus::Accepted));
        $pendingProject = $this->projectOf($otherOwner, 'Pending Hecate', ProjectStatus::Published);
        $pendingProject->addMember($this->ownerParticipant($otherOwner));
        $pendingProject->addMember($this->participantOf($owner, ParticipantRole::Member, ParticipantStatus::Pending));
        $strangerProject = $this->projectOf($otherOwner, 'Stranger Cutlass', ProjectStatus::Published);
        $strangerProject->addMember($this->ownerParticipant($otherOwner));
        $this->em->flush();
        $ownerId = (string) $owner->getId();
        $expected = [
            sprintf('/group-project/%s/events', $soloProject->getId()),
            sprintf('/group-project/%s/events', $groupProject->getId()),
        ];
        sort($expected);
        $this->em->clear();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $ownerId));
        $provider = $this->tokenProvider($security);

        $projectTopics = array_values(array_filter(
            $provider->provide(new Get(), [], ['request' => $this->requestToTheHub()])->topics,
            static fn (string $topic): bool => str_starts_with($topic, '/group-project/'),
        ));
        sort($projectTopics);

        self::assertSame($expected, $projectTopics);
    }

    public function testTheSubscriberTokenTravelsInTheMercureAuthorizationCookie(): void
    {
        $user = $this->persistedUserWithProjects(0);
        $request = $this->requestToTheHub();

        $resource = $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $request]);

        $cookie = $this->authorizationCookie($request);
        self::assertSame('__Secure-mercure_access_token', $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertSame($resource->token, $cookie->getValue());
    }

    public function testTheSubscriberTokenIsAProtocolOneAccessTokenBoundToTheHubIssuer(): void
    {
        $user = $this->persistedUserWithProjects(0);
        $request = $this->requestToTheHub();

        $resource = $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $request]);

        [$header, $payload] = $this->decodeJwt($resource->token);
        $resourceIdentifier = $_SERVER['MERCURE_RESOURCE_IDENTIFIER'] ?? $_ENV['MERCURE_RESOURCE_IDENTIFIER'];
        self::assertSame('at+jwt', $header['typ']);
        self::assertSame($resourceIdentifier, $payload['iss']);
        self::assertSame([$resourceIdentifier], (array) $payload['aud']);
        self::assertNotEmpty($payload['sub']);
        self::assertNotEmpty($payload['client_id']);
        self::assertGreaterThan(time(), $payload['exp']);
    }

    public function testTheSubscriberTokenGrantsSubscribeOnEveryTopicOfTheResource(): void
    {
        $user = $this->persistedUserWithProjects(2);
        $request = $this->requestToTheHub();

        $resource = $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $request]);

        [, $payload] = $this->decodeJwt($resource->token);
        $details = $payload['authorization_details'];
        foreach ($details as $detail) {
            self::assertSame(['subscribe'], $detail['actions']);
        }
        foreach ($resource->topics as $topic) {
            self::assertTrue($this->isGranted($topic, $details), $topic);
        }
    }

    public function testTheSubscriberTokenNeverGrantsTheTopicsOfAnotherUser(): void
    {
        $user = $this->persistedUserWithProjects(1);
        $request = $this->requestToTheHub();

        $resource = $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $request]);

        [, $payload] = $this->decodeJwt($resource->token);
        $details = $payload['authorization_details'];
        $otherUserId = '00000000-0000-4000-8000-000000613999';
        foreach (MercurePublisherService::getTopicsForUser($otherUserId) as $topic) {
            self::assertFalse($this->isGranted($topic, $details), $topic);
        }
        self::assertFalse($this->isGranted(sprintf('/group-project/%s/events', $otherUserId), $details));
    }

    public function testTheAuthorizationCookieFitsInTheBrowserLimitForAUserOfThirtyProjects(): void
    {
        $user = $this->persistedUserWithProjects(30);
        $request = $this->requestToTheHub();

        $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $request]);

        self::assertLessThan(4096, strlen($this->authorizationCookie($request)->__toString()));
    }

    public function testTheTokenIsRefusedLoudlyWhenTheAuthorizationCookieExceedsTheBrowserLimit(): void
    {
        $user = $this->persistedUserWithProjects(60);

        $this->expectException(\LengthException::class);

        $this->tokenProvider($this->securityOf($user))->provide(new Get(), [], ['request' => $this->requestToTheHub()]);
    }

    private function tokenProvider(Security $security): TokenProvider
    {
        return new TokenProvider(
            $security,
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(Authorization::class),
            'https://mercure.example.test/.well-known/mercure',
        );
    }

    private function securityOf(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function persistedUserWithProjects(int $projectCount): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, 'Mira Tolland')->setCorporationId(98_613_201));
        for ($index = 0; $index < $projectCount; ++$index) {
            $project = $this->projectOf($user, sprintf('Project %d', $index), ProjectStatus::InProgress);
            $project->addMember($this->ownerParticipant($user));
        }
        $this->em->flush();
        $userId = (string) $user->getId();
        $this->em->clear();

        return $this->em->find(User::class, $userId);
    }

    /**
     * @param list<array<string, mixed>> $details
     */
    private function isGranted(string $topic, array $details): bool
    {
        foreach ($details as $detail) {
            foreach ($detail['topics'] as $matcher) {
                $pattern = $matcher['match'];
                $isGranted = match ($matcher['match_type'] ?? 'exact') {
                    'exact' => $pattern === $topic,
                    'urlpattern' => str_ends_with($pattern, '/*') && str_starts_with($topic, substr($pattern, 0, -1)),
                    default => false,
                };
                if ($isGranted) {
                    return true;
                }
            }
        }

        return false;
    }

    private function requestToTheHub(): Request
    {
        return Request::create(self::getContainer()->get(HubInterface::class)->getPublicUrl());
    }

    private function authorizationCookie(Request $request): Cookie
    {
        return $request->attributes->get('_mercure_authorization_cookies')[''];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function decodeJwt(string $jwt): array
    {
        [$header, $payload] = explode('.', $jwt);

        return [
            json_decode(base64_decode(strtr($header, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR),
            json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function projectOf(User $owner, string $name, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $this->em->persist($project);

        return $project;
    }

    private function participantOf(User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        return (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
    }

    private function ownerParticipant(User $user): IndustryProjectParticipant
    {
        return (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
    }
}
