<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use App\Entity\GroupIndustryProject;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Processor\GroupIndustry\DeleteGroupProjectProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeleteGroupProjectProcessor::class)]
class DeleteGroupProjectProcessorTest extends TestCase
{
    public function testRemovesAndFlushesTheProjectFoundByItsId(): void
    {
        $projectId = Uuid::v4();
        $project = new GroupIndustryProject();
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            static fn (Uuid $id): ?GroupIndustryProject => $id->equals($projectId) ? $project : null,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($this->identicalTo($project));
        $entityManager->expects($this->once())->method('flush');

        (new DeleteGroupProjectProcessor($projectRepository, $entityManager))
            ->process(null, new Delete(), ['id' => $projectId->toRfc4122()]);
    }

    public function testDoesNothingWhenTheProjectIsAbsent(): void
    {
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        (new DeleteGroupProjectProcessor($projectRepository, $entityManager))
            ->process(null, new Delete(), ['id' => Uuid::v4()->toRfc4122()]);
    }
}
