<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Planetary;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Planetary\FollowPlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\State\Processor\Planetary\FollowPlanTemplateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(FollowPlanTemplateProcessor::class)]
#[Group('issue-1406')]
final class FollowPlanTemplateProcessorTest extends TestCase
{
    private const string TEMPLATE_ID = '5d2a9c71-8e04-4b36-a1f7-3c60b8d9e215';

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testFollowingATemplateMarksItFollowedFlushesOnceAndReturnsItFollowed(): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Robotics ring');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processor($this->repositoryFinding($template), $entityManager)->process($this->input(true), new Patch(), ['id' => self::TEMPLATE_ID]);

        $this->assertTrue($template->isFollowed());
        $this->assertInstanceOf(PlanetaryPlanTemplateResource::class, $resource);
        $this->assertSame('Robotics ring', $resource->name);
        $this->assertTrue($resource->followed);
    }

    public function testStoppingToFollowAFollowedTemplateMarksItNotFollowedAndReturnsItNotFollowed(): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Robotics ring')->setFollowed(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processor($this->repositoryFinding($template), $entityManager)->process($this->input(false), new Patch(), ['id' => self::TEMPLATE_ID]);

        $this->assertFalse($template->isFollowed());
        $this->assertFalse($resource->followed);
    }

    public function testStoppingToFollowATemplateThatIsNotFollowedLeavesItNotFollowed(): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Coolant line');

        $resource = $this->processor($this->repositoryFinding($template), $this->createStub(EntityManagerInterface::class))->process($this->input(false), new Patch(), ['id' => self::TEMPLATE_ID]);

        $this->assertFalse($template->isFollowed());
        $this->assertFalse($resource->followed);
    }

    public function testFollowingATemplateAlreadyFollowedKeepsItFollowed(): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Coolant line')->setFollowed(true);

        $resource = $this->processor($this->repositoryFinding($template), $this->createStub(EntityManagerInterface::class))->process($this->input(true), new Patch(), ['id' => self::TEMPLATE_ID]);

        $this->assertTrue($template->isFollowed());
        $this->assertTrue($resource->followed);
    }

    #[DataProvider('notStrictBooleans')]
    public function testAFollowedValueThatIsNotABooleanIsRefusedOnTheFollowedFieldAndTheTemplateIsUntouched(mixed $followed): void
    {
        $template = (new PlanetaryPlanTemplate())->setUser($this->user)->setName('Coolant line')->setFollowed(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processor($this->repositoryFinding($template), $entityManager)->process($this->input($followed), new Patch(), ['id' => self::TEMPLATE_ID]);
        } catch (ValidationException $refusal) {
            $fields = [];
            foreach ($refusal->getConstraintViolationList() as $violation) {
                $fields[] = $violation->getPropertyPath();
            }
            $this->assertSame(['followed'], array_values(array_unique($fields)));
            $this->assertTrue($template->isFollowed());

            return;
        }

        $this->fail('The followed value was not refused');
    }

    public static function notStrictBooleans(): iterable
    {
        yield 'null, as when the body has no followed' => [null];
        yield 'the string yes' => ['yes'];
        yield 'the string true' => ['true'];
        yield 'the integer 1' => [1];
        yield 'the integer 0' => [0];
        yield 'a list' => [[true]];
    }

    public function testAnIdThatIsNotTheUsersIsAnUnknownTemplateAndNothingIsFlushed(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $repository = $this->createStub(PlanetaryPlanTemplateRepository::class);
        $repository->method('findOneByUserAndId')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->processor($repository, $entityManager)->process($this->input(true), new Patch(), ['id' => self::TEMPLATE_ID]);
    }

    public function testAnIdThatIsNotAUuidIsAnUnknownTemplateWithoutLookingItUp(): void
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->never())->method('findOneByUserAndId');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->processor($repository, $entityManager)->process($this->input(true), new Patch(), ['id' => 'not-a-uuid']);
    }

    public function testAnAnonymousCallerIsRefusedAndNothingIsFlushed(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor($this->createStub(PlanetaryPlanTemplateRepository::class), $entityManager, false)->process($this->input(true), new Patch(), ['id' => self::TEMPLATE_ID]);
    }

    private function repositoryFinding(PlanetaryPlanTemplate $template): PlanetaryPlanTemplateRepository
    {
        $repository = $this->createMock(PlanetaryPlanTemplateRepository::class);
        $repository->expects($this->once())->method('findOneByUserAndId')
            ->with($this->user, $this->callback(static fn (Uuid $id): bool => $id->toRfc4122() === self::TEMPLATE_ID))
            ->willReturn($template);

        return $repository;
    }

    private function processor(PlanetaryPlanTemplateRepository $repository, EntityManagerInterface $entityManager, bool $authenticated = true): FollowPlanTemplateProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->user : null);

        return new FollowPlanTemplateProcessor($security, $repository, $entityManager);
    }

    private function input(mixed $followed): FollowPlanTemplateInput
    {
        $input = new FollowPlanTemplateInput();
        $input->followed = $followed;

        return $input;
    }
}
