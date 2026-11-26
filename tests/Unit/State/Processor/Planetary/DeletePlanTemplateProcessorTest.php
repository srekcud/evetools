<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Planetary;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\State\Processor\Planetary\DeletePlanTemplateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeletePlanTemplateProcessor::class)]
#[Group('issue-1405')]
final class DeletePlanTemplateProcessorTest extends TestCase
{
    private const string TEMPLATE_ID = '0b6f4e0a-5c1d-4a52-9d3b-6a7e2f9c1d84';

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testItRemovesTheTemplateOfTheConnectedUserFoundByItsIdAndFlushesOnce(): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Coolant line');
        $removed = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });
        $entityManager->expects($this->once())->method('flush');
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->once())->method('findOneByUserAndId')
            ->with($this->user, $this->callback(static fn (Uuid $id): bool => $id->toRfc4122() === self::TEMPLATE_ID))
            ->willReturn($template);

        $this->processor($repository, $entityManager)->process($this->resource(), new Delete(), ['id' => self::TEMPLATE_ID]);

        $this->assertSame([$template], $removed);
    }

    public function testAnIdThatIsNotTheUsersIsAnUnknownTemplateAndNothingIsRemoved(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');
        $repository = $this->createStub(PlanetaryPlanTemplateRepository::class);
        $repository->method('findOneByUserAndId')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->processor($repository, $entityManager)->process($this->resource(), new Delete(), ['id' => self::TEMPLATE_ID]);
    }

    public function testAnIdThatIsNotAUuidIsAnUnknownTemplateWithoutLookingItUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->never())->method('findOneByUserAndId');

        $this->expectException(NotFoundHttpException::class);

        $this->processor($repository, $entityManager)->process($this->resource(), new Delete(), ['id' => 'not-a-uuid']);
    }

    public function testAnAnonymousCallerIsRefusedAndNothingIsRemoved(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');
        $repository = $this->createStub(PlanetaryPlanTemplateRepository::class);

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor($repository, $entityManager, false)->process($this->resource(), new Delete(), ['id' => self::TEMPLATE_ID]);
    }

    private function processor(PlanetaryPlanTemplateRepository $repository, EntityManagerInterface $entityManager, bool $authenticated = true): DeletePlanTemplateProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->user : null);

        return new DeletePlanTemplateProcessor($security, $repository, $entityManager);
    }

    private function resource(): PlanetaryPlanTemplateResource
    {
        $resource = new PlanetaryPlanTemplateResource();
        $resource->id = self::TEMPLATE_ID;

        return $resource;
    }
}
