<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
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
#[Group('issue-200')]
final class ProjectProviderTest extends TestCase
{
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjects(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $provider = new ProjectProvider(
            $security,
            $projectRepository,
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(ProductionTreeFromSteps::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $provider->provide(new Get(), ['id' => 'xyz']);
    }
}
