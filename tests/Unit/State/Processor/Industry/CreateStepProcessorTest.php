<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(CreateStepProcessor::class)]
#[Group('issue-200')]
final class CreateStepProcessorTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000001';

    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjects(): void
    {
        $user = new User();
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');
        $input = new CreateStepInput();
        $input->typeId = 11540;

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor($user, $projectRepository, $stepRepository)
            ->process($input, new Post(), ['id' => 'xyz']);
    }

    public function testStepIdOfTheBodyThatIsNotAUuidIsNotFoundWithoutReadingTheSteps(): void
    {
        $user = new User();
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn((new IndustryProject())->setUser($user));
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');
        $input = new CreateStepInput();
        $input->stepId = 's-1';
        $input->runs = 3;

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $this->processor($user, $projectRepository, $stepRepository)
            ->process($input, new Post(), ['id' => self::PROJECT_ID]);
    }

    private function processor(User $user, IndustryProjectRepository $projectRepository, IndustryProjectStepRepository $stepRepository): CreateStepProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        return new CreateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(IndustryResourceMapper::class),
            $entityManager,
        );
    }
}
