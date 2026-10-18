<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\User;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\InventionService;
use App\Service\Industry\ProductionCostService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Processor\Industry\CreateProjectProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(CreateProjectProcessor::class)]
final class CreateProjectProcessorTest extends TestCase
{
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int ROBOTICS = 9848;

    public function testProjectOnATreeWithATypeMissingFromTheSdeIsABadRequestNamingTheMissingType(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $projectFactory = $this->createStub(IndustryProjectFactory::class);
        $projectFactory->method('createProject')->willThrowException(new TypeMissingFromSdeException([self::ROBOTICS]));
        $processor = new CreateProjectProcessor(
            $security,
            $projectFactory,
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(InventionService::class),
            $this->createStub(ProductionCostService::class),
            $this->createStub(JitaMarketService::class),
            $this->createStub(StructureMarketService::class),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(EntityManagerInterface::class),
        );
        $input = new CreateProjectInput();
        $input->typeId = self::NITROGEN_FUEL_BLOCK;
        $input->runs = 25;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Types missing from the SDE: 9848');

        $processor->process($input, new Post());
    }
}
