<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Mercure;

use ApiPlatform\Metadata\Get;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\State\Provider\Mercure\TokenProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-362')]
final class TokenProviderTest extends IntegrationTestCase
{
    public function testSubscriberTopicsIncludeTheEventsOfTheProjectWithItemsAndNotThoseOfTheSoloProject(): void
    {
        $owner = $this->createUser();
        $owner->setMainCharacter($this->createCharacter($owner, 'Kaelen Voss')->setCorporationId(98_362_201));
        $soloProject = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Rifter by steps')
            ->setStatus(GroupProjectStatus::Draft)
            ->setProductTypeId(587)
            ->setRuns(10)
            ->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(586)
                    ->setProductTypeId(587)
                    ->setQuantity(10)
                    ->setRuns(10)
                    ->setDepth(0),
            );
        $soloProject->addMember($this->ownerParticipant($owner));
        $groupProject = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Sabre by items')
            ->setStatus(GroupProjectStatus::Published)
            ->setProductTypeId(22456)
            ->setRuns(5)
            ->addItem(
                (new GroupIndustryProjectItem())
                    ->setTypeId(22456)
                    ->setTypeName('Sabre')
                    ->setMeLevel(10)
                    ->setTeLevel(20)
                    ->setRuns(5),
            );
        $groupProject->addMember($this->ownerParticipant($owner));
        $this->em->persist($soloProject);
        $this->em->persist($groupProject);
        $this->em->flush();
        $ownerId = (string) $owner->getId();
        $soloProjectId = (string) $soloProject->getId();
        $groupProjectId = (string) $groupProject->getId();
        $this->em->clear();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $ownerId));
        $provider = new TokenProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectMemberRepository::class),
            'a-mercure-secret-of-at-least-thirty-two-bytes',
            'https://mercure.example.test/.well-known/mercure',
        );

        $topics = $provider->provide(new Get())->topics;

        self::assertContains(sprintf('/group-project/%s/events', $groupProjectId), $topics);
        self::assertNotContains(sprintf('/group-project/%s/events', $soloProjectId), $topics);
        self::assertCount(1, array_filter($topics, static fn (string $topic): bool => str_starts_with($topic, '/group-project/')));
    }

    private function ownerParticipant(User $user): GroupIndustryProjectMember
    {
        return (new GroupIndustryProjectMember())
            ->setUser($user)
            ->setRole(GroupMemberRole::Owner)
            ->setStatus(GroupMemberStatus::Accepted);
    }
}
