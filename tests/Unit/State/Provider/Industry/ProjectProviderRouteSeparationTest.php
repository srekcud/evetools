<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\ProductionTreeFromSteps;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\ProjectProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ProjectProvider::class)]
#[Group('issue-362')]
final class ProjectProviderRouteSeparationTest extends TestCase
{
    public function testOwnerReadingAProjectWithItemsOnTheSoloRouteGetsProjectNotFound(): void
    {
        $owner = new User();
        $projectWithItems = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Sabre by items')
            ->setStatus(GroupProjectStatus::Published)
            ->addItem(
                (new GroupIndustryProjectItem())
                    ->setTypeId(22456)
                    ->setTypeName('Sabre')
                    ->setMeLevel(10)
                    ->setTeLevel(20)
                    ->setRuns(5),
            );
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($projectWithItems);
        $provider = new ProjectProvider(
            $security,
            $projectRepository,
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(ProductionTreeFromSteps::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $provider->provide(new Get(), ['id' => (string) $projectWithItems->getId()]);
    }
}
