<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\UpdateProjectInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\State\Processor\Industry\UpdateProjectProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UpdateProjectProcessor::class)]
#[Group('issue-200')]
final class UpdateProjectProcessorTest extends TestCase
{
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjectsNorFlushing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $processor = new UpdateProjectProcessor(
            $security,
            $projectRepository,
            $this->createStub(IndustryProjectFactory::class),
            $this->createStub(IndustryResourceMapper::class),
            $entityManager,
        );
        $input = new UpdateProjectInput();
        $input->name = 'A';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $processor->process($input, new Patch(), ['id' => 'not-a-uuid']);
    }
}
