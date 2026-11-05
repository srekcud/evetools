<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupProjectProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(GroupProjectProvider::class)]
#[Group('issue-362')]
final class GroupProjectProviderRouteSeparationTest extends TestCase
{
    public function testOwnerAcceptedParticipantReadingAProjectPlannedByStepsOnTheGroupRouteGetsProjectNotFound(): void
    {
        $owner = new User();
        $projectPlannedBySteps = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Rifter by steps')
            ->setStatus(GroupProjectStatus::Published)
            ->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(588)
                    ->setProductTypeId(587)
                    ->setQuantity(12)
                    ->setRuns(12)
                    ->setDepth(0),
            );
        $ownerParticipant = (new IndustryProjectParticipant())
            ->setUser($owner)
            ->setRole(GroupMemberRole::Owner)
            ->setStatus(GroupMemberStatus::Accepted);
        $projectPlannedBySteps->addMember($ownerParticipant);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($projectPlannedBySteps);
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findOneBy')->willReturn($ownerParticipant);
        $provider = new GroupProjectProvider(
            $security,
            $projectRepository,
            $memberRepository,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $provider->provide(new Get(), ['id' => (string) $projectPlannedBySteps->getId()]);
    }
}
