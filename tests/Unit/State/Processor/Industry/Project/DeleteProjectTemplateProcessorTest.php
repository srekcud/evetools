<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Delete;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Processor\Industry\Project\DeleteProjectTemplateProcessor;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsProjectTemplates;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeleteProjectTemplateProcessor::class)]
#[Group('issue-355')]
final class DeleteProjectTemplateProcessorTest extends TestCase
{
    use BuildsProjectTemplates;

    public function testTemplateIsRemovedAndNothingElse(): void
    {
        $alice = $this->pilot('Alice Ferrand', 98_000_001);
        $template = $this->templateOf($alice, 'Hammerhead line', true);
        $repository = $this->createStub(IndustryProjectTemplateRepository::class);
        $repository->method('find')->willReturn($template);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($template);
        $entityManager->expects($this->once())->method('flush');

        (new DeleteProjectTemplateProcessor($repository, $entityManager))
            ->process(null, new Delete(), ['id' => (string) $template->getId()]);
    }
}
