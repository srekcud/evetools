<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\ProductionStructureRigsValidator;
use App\State\Processor\Industry\UpdateStructureProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UpdateStructureProcessor::class)]
#[Group('issue-200')]
final class UpdateStructureProcessorTest extends TestCase
{
    public function testStructureIdThatIsNotAUuidIsNotFoundWithoutReadingTheStructuresNorFlushing(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $structureConfigRepository = $this->createMock(IndustryStructureConfigRepository::class);
        $structureConfigRepository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $processor = new UpdateStructureProcessor(
            $security,
            $structureConfigRepository,
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(IndustryResourceMapper::class),
            $entityManager,
            new ProductionStructureRigsValidator(),
        );
        $input = new UpdateStructureInput();
        $input->name = 'B';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Structure not found');

        $processor->process($input, new Patch(), ['id' => 'rig-3']);
    }
}
