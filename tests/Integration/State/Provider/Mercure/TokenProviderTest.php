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
        $provider = new TokenProvider(
            $security,
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            'a-mercure-secret-of-at-least-thirty-two-bytes',
            'https://mercure.example.test/.well-known/mercure',
        );

        $projectTopics = array_values(array_filter(
            $provider->provide(new Get())->topics,
            static fn (string $topic): bool => str_starts_with($topic, '/group-project/'),
        ));
        sort($projectTopics);

        self::assertSame($expected, $projectTopics);
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
