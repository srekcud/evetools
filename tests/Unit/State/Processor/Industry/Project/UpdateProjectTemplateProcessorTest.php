<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\ApiResource\Input\Industry\Project\UpdateProjectTemplateInput;
use App\Entity\IndustryProjectTemplate;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Processor\Industry\Project\UpdateProjectTemplateProcessor;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsProjectTemplates;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UpdateProjectTemplateProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-355')]
final class UpdateProjectTemplateProcessorTest extends TestCase
{
    use BuildsProjectTemplates;

    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectTemplate $template;
    private UpdateProjectTemplateProcessor $processor;

    protected function setUp(): void
    {
        $alice = $this->pilot('Alice Ferrand', 98_000_001);
        $this->template = $this->templateOf($alice, 'Hammerhead line', false);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($alice);
        $repository = $this->createStub(IndustryProjectTemplateRepository::class);
        $repository->method('find')->willReturn($this->template);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('countByTemplate')->willReturn([(string) $this->template->getId() => 2]);
        $this->processor = new UpdateProjectTemplateProcessor($security, $repository, $this->entityManager, $projectRepository);
    }

    public function testOwnerRenamesTheTemplateAndSharesItWithTheCorporation(): void
    {
        $input = new UpdateProjectTemplateInput();
        $input->name = 'Hammerhead line, ME 10';
        $input->sharedWithCorporation = true;
        $this->entityManager->expects($this->once())->method('flush');

        $resource = $this->update($input);

        self::assertSame('Hammerhead line, ME 10', $this->template->getName());
        self::assertTrue($this->template->isSharedWithCorporation());
        self::assertSame('Hammerhead line, ME 10', $resource->name);
        self::assertTrue($resource->sharedWithCorporation);
        self::assertSame(2, $resource->usageCount);
    }

    public function testPatchWithoutNameKeepsTheNameAndOnlyChangesTheSharing(): void
    {
        $this->template->setSharedWithCorporation(true);
        $input = new UpdateProjectTemplateInput();
        $input->sharedWithCorporation = false;

        $resource = $this->update($input);

        self::assertSame('Hammerhead line', $this->template->getName());
        self::assertFalse($this->template->isSharedWithCorporation());
        self::assertSame('Hammerhead line', $resource->name);
        self::assertSame(2, count($this->template->getItems()));
    }

    private function update(UpdateProjectTemplateInput $input): IndustryProjectTemplateResource
    {
        return $this->processor->process($input, new Patch(), ['id' => (string) $this->template->getId()]);
    }
}
