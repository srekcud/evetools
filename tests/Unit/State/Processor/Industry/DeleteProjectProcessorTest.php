<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\State\Processor\Industry\DeleteProjectProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(DeleteProjectProcessor::class)]
#[Group('issue-200')]
final class DeleteProjectProcessorTest extends TestCase
{
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutRemovingNorFlushing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        (new DeleteProjectProcessor($security, $projectRepository, $entityManager))
            ->process(null, new Delete(), ['id' => '42']);
    }
}
