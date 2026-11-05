<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Mercure;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\State\Provider\Mercure\TokenProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

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
            ->setStatus(GroupProjectStatus::Published)
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
        $provider = new TokenProvider(
            $security,
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            'a-mercure-secret-of-at-least-thirty-two-bytes',
            'https://mercure.example.test/.well-known/mercure',
        );

        $topics = $provider->provide(new Get())->topics;

        self::assertContains(sprintf('/group-project/%s/events', $groupProjectId), $topics);
        self::assertCount(1, array_filter($topics, static fn (string $topic): bool => str_starts_with($topic, '/group-project/')));
    }

    private function ownerParticipant(User $user): IndustryProjectParticipant
    {
        return (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole(GroupMemberRole::Owner)
            ->setStatus(GroupMemberStatus::Accepted);
    }
}
