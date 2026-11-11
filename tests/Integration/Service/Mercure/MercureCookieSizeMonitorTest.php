<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mercure;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\UserRepository;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\State\Provider\Mercure\TokenProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-649')]
final class MercureCookieSizeMonitorTest extends IntegrationTestCase
{
    private const int PROJECTS_BELOW_THE_WARNING = 5;
    private const int PROJECTS_ABOVE_THE_WARNING_AND_BELOW_THE_LIMIT = 28;
    private const int PROJECTS_ABOVE_THE_LIMIT = 60;
    private const int MICROSECOND_CLAIM_JITTER_BYTES = 8;

    public function testTheWarningThresholdIsEightyPercentOfTheBrowserLimit(): void
    {
        self::assertSame(3276, $this->monitor()->warningThresholdBytes());
    }

    public function testUsersFarFromTheBrowserLimitAreNotListed(): void
    {
        $this->persistedUserWithProjects(self::PROJECTS_BELOW_THE_WARNING, 'Mira Tolland');
        $this->persistedUserWithProjects(0, 'Dax Orrin');

        self::assertSame([], $this->monitor()->usersNearLimit());
    }

    public function testAUserAboveTheWarningThresholdIsListedWithTheSizeOfTheCookieTheTokenProviderSends(): void
    {
        $user = $this->persistedUserWithProjects(self::PROJECTS_ABOVE_THE_WARNING_AND_BELOW_THE_LIMIT, 'Mira Tolland');
        $request = Request::create(self::getContainer()->get(HubInterface::class)->getPublicUrl());
        $resource = $this->tokenProvider($user)->provide(new Get(), [], ['request' => $request]);
        $sentCookieBytes = strlen((string) $request->attributes->get('_mercure_authorization_cookies')['']);

        $usages = $this->monitor()->usersNearLimit();

        self::assertCount(1, $usages);
        self::assertSame((string) $user->getId(), $usages[0]->userId);
        self::assertSame('Mira Tolland', $usages[0]->mainCharacterName);
        self::assertEqualsWithDelta($sentCookieBytes, $usages[0]->cookieBytes, self::MICROSECOND_CLAIM_JITTER_BYTES);
        self::assertSame(count($resource->topics), $usages[0]->topicCount);
        self::assertGreaterThanOrEqual($this->monitor()->warningThresholdBytes(), $usages[0]->cookieBytes);
        self::assertLessThan(4096, $usages[0]->cookieBytes);
    }

    public function testAUserAlreadyAboveTheBrowserLimitIsStillListed(): void
    {
        $this->persistedUserWithProjects(self::PROJECTS_ABOVE_THE_LIMIT, 'Dax Orrin');

        $usages = $this->monitor()->usersNearLimit();

        self::assertCount(1, $usages);
        self::assertGreaterThan(4096, $usages[0]->cookieBytes);
    }

    public function testTheLargestCookiesComeFirst(): void
    {
        $smaller = $this->persistedUserWithProjects(self::PROJECTS_ABOVE_THE_WARNING_AND_BELOW_THE_LIMIT, 'Mira Tolland');
        $larger = $this->persistedUserWithProjects(self::PROJECTS_ABOVE_THE_LIMIT, 'Dax Orrin');

        $usages = $this->monitor()->usersNearLimit();

        self::assertSame([(string) $larger->getId(), (string) $smaller->getId()], array_map(static fn ($usage): string => $usage->userId, $usages));
    }

    private function monitor(): MercureCookieSizeMonitor
    {
        return new MercureCookieSizeMonitor(
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(Authorization::class),
            self::getContainer()->get(HubInterface::class)->getPublicUrl(),
        );
    }

    private function tokenProvider(User $user): TokenProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new TokenProvider(
            $security,
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(Authorization::class),
            'https://mercure.example.test/.well-known/mercure',
        );
    }

    private function persistedUserWithProjects(int $projectCount, string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName)->setCorporationId(98_649_201));
        for ($index = 0; $index < $projectCount; ++$index) {
            $project = (new IndustryProject())->setOwner($user)->setName(sprintf('Project %d', $index))->setStatus(ProjectStatus::InProgress);
            $this->em->persist($project);
            $project->addMember(
                (new IndustryProjectParticipant())
                    ->setUser($user)
                    ->setRole(ParticipantRole::Owner)
                    ->setStatus(ParticipantStatus::Accepted),
            );
        }
        $this->em->flush();
        $userId = (string) $user->getId();
        $this->em->clear();

        return $this->em->find(User::class, $userId);
    }
}
