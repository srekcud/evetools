<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\State\Processor\GroupIndustry\CreateGroupProjectProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(CreateGroupProjectProcessor::class)]
class CreateGroupProjectProcessorTest extends TestCase
{
    private Security&Stub $security;
    private GroupIndustryProjectService&Stub $projectService;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupIndustryResourceMapper&Stub $mapper;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private CreateGroupProjectProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectService = $this->createStub(GroupIndustryProjectService::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->mapper = $this->createStub(GroupIndustryResourceMapper::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);

        $this->processor = new CreateGroupProjectProcessor(
            $this->security,
            $this->projectService,
            $this->memberRepository,
            $this->mapper,
            $this->invTypeRepository,
            $this->activityProductRepository,
        );
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process(new CreateGroupProjectInput(), new Post());
    }

    public function testThrowsBadRequestWhenNoItems(): void
    {
        $this->security->method('getUser')->willReturn($this->createUser());

        $input = new CreateGroupProjectInput();
        $input->items = [];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('At least one item is required');

        $this->processor->process($input, new Post());
    }

    public function testResolvesTypeIdFromNameWhenTypeIdIsZero(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        $invType = new InvType();
        $invType->setTypeId(17715);
        $invType->setTypeName('Redeemer');

        $this->invTypeRepository
            ->expects($this->once())
            ->method('findOneByName')
            ->with('redeemer')
            ->willReturn($invType);

        $product = new IndustryActivityProduct();
        $product->setTypeId(999);
        $product->setActivityId(1);
        $product->setProductTypeId(17715);
        $product->setQuantity(1);

        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $typeId, int $activityId) use ($product): ?IndustryActivityProduct {
                if ($typeId === 17715 && $activityId === 1) {
                    return $product;
                }
                return null;
            });

        $project = $this->createStub(GroupIndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(GroupIndustryProjectMember::class);
        $this->memberRepository->method('findOneBy')->willReturn($ownerMember);

        $expectedResource = new GroupIndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 0, 'typeName' => 'redeemer', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 10],
        ];

        $result = $this->processor->process($input, new Post());

        $this->assertSame($expectedResource, $result);
        // After processing, the input items should have resolved typeId and proper typeName
        $this->assertSame(17715, $input->items[0]['typeId']);
        $this->assertSame('Redeemer', $input->items[0]['typeName']);
    }

    public function testThrowsBadRequestWhenTypeNameNotFoundInSde(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        $this->invTypeRepository
            ->method('findOneByName')
            ->willReturn(null);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 0, 'typeName' => 'nonexistent_ship', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
        ];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Unknown item(s): nonexistent_ship');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestWhenMultipleNamesNotFound(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        $this->invTypeRepository
            ->method('findOneByName')
            ->willReturn(null);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 0, 'typeName' => 'unknown_one', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ['typeId' => 0, 'typeName' => 'unknown_two', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
        ];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Unknown item(s): unknown_one, unknown_two');

        $this->processor->process($input, new Post());
    }

    public function testThrowsBadRequestWhenNoBlueprintFound(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        // Item already has a typeId (not from bulk paste), but no blueprint
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
        ];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for: Tritanium');

        $this->processor->process($input, new Post());
    }

    public function testSkipsResolutionWhenTypeIdIsNonZero(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        // Should never call findOneByName for items with typeId > 0
        $this->invTypeRepository
            ->expects($this->never())
            ->method('findOneByName');

        $product = new IndustryActivityProduct();
        $product->setTypeId(999);
        $product->setActivityId(1);
        $product->setProductTypeId(17715);
        $product->setQuantity(1);

        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn($product);

        $project = $this->createStub(GroupIndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(GroupIndustryProjectMember::class);
        $this->memberRepository->method('findOneBy')->willReturn($ownerMember);

        $expectedResource = new GroupIndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 17715, 'typeName' => 'Redeemer', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 10],
        ];

        $result = $this->processor->process($input, new Post());

        $this->assertSame($expectedResource, $result);
    }

    public function testFallsBackToReactionWhenNoManufacturingBlueprint(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);

        $reactionProduct = new IndustryActivityProduct();
        $reactionProduct->setTypeId(888);
        $reactionProduct->setActivityId(11);
        $reactionProduct->setProductTypeId(16670);
        $reactionProduct->setQuantity(200);

        // Manufacturing returns null, reaction returns a result
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $typeId, int $activityId) use ($reactionProduct): ?IndustryActivityProduct {
                if ($typeId === 16670 && $activityId === 11) {
                    return $reactionProduct;
                }
                return null;
            });

        $project = $this->createStub(GroupIndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(GroupIndustryProjectMember::class);
        $this->memberRepository->method('findOneBy')->willReturn($ownerMember);

        $expectedResource = new GroupIndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateGroupProjectInput();
        $input->items = [
            ['typeId' => 16670, 'typeName' => 'Fullerides', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 5],
        ];

        $result = $this->processor->process($input, new Post());

        $this->assertSame($expectedResource, $result);
    }

    private function createUser(): User&Stub
    {
        return $this->createStub(User::class);
    }
}
