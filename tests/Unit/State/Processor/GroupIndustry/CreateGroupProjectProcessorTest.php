<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\State\Processor\GroupIndustry\CreateGroupProjectProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(CreateGroupProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class CreateGroupProjectProcessorTest extends TestCase
{
    private const int SABRE_TYPE_ID = 22456;
    private const int SABRE_BLUEPRINT_TYPE_ID = 22457;

    private Security&Stub $security;
    private GroupIndustryProjectService&Stub $projectService;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupIndustryResourceMapper&Stub $mapper;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private CreateGroupProjectProcessor $processor;
    private User&Stub $owner;
    private ?IndustryUserSettings $ownerSettings = null;
    private ?CreateProjectData $createdProjectData = null;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectService = $this->createStub(GroupIndustryProjectService::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->mapper = $this->createStub(GroupIndustryResourceMapper::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->owner = $this->createUser();
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryUserSettings => ($criteria['user'] ?? null) === $this->owner ? $this->ownerSettings : null,
        );

        $this->processor = new CreateGroupProjectProcessor(
            $this->security,
            $this->projectService,
            $this->memberRepository,
            $this->mapper,
            $this->invTypeRepository,
            $this->activityProductRepository,
            $settingsRepository,
        );
    }

    public static function projectFeesProvider(): iterable
    {
        yield 'F1 no industry settings and no fee sent fall back to the default rates' => [null, null, null, 3.6, 3.6];
        yield 'F2 industry settings give the fee of each absent fee' => [[0.015, 0.045], null, null, 1.5, 4.5];
        yield 'F3 industry settings at the default rates give exactly 3.6 percent' => [[0.036, 0.036], null, null, 3.6, 3.6];
        yield 'F4 fees sent are kept over the industry settings, zero included' => [[0.015, 0.045], 2.0, 0.0, 2.0, 0.0];
        yield 'F5 a broker fee rate of zero in the settings is a setting' => [[0.0, 0.036], null, null, 0.0, 3.6];
        yield 'F6 only the sales tax sent, the broker fee comes from the settings' => [[0.014234, 0.045], null, 3.0, 1.4234, 3.0];
        yield 'F7 a broker fee of zero sent without settings is kept, the sales tax falls back' => [null, 0.0, null, 0.0, 3.6];
        yield 'F8 only the broker fee sent, the sales tax comes from the settings' => [[0.015, 0.045], 2.0, null, 2.0, 4.5];
    }

    #[Group('issue-170')]
    #[DataProvider('projectFeesProvider')]
    public function testFeeAbsentAtCreationTakesTheIndustrySettingsOfTheOwnerInPercent(?array $ownerRates, ?float $brokerFeePercentSent, ?float $salesTaxPercentSent, float $expectedBrokerFeePercent, float $expectedSalesTaxPercent): void
    {
        if ($ownerRates !== null) {
            $this->ownerSettings = (new IndustryUserSettings())
                ->setUser($this->owner)
                ->setBrokerFeeRate($ownerRates[0])
                ->setSalesTaxRate($ownerRates[1]);
        }
        $input = $this->sabreInput();
        $input->brokerFeePercent = $brokerFeePercentSent;
        $input->salesTaxPercent = $salesTaxPercentSent;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame($expectedBrokerFeePercent, $this->createdProjectData->brokerFeePercent);
        self::assertSame($expectedSalesTaxPercent, $this->createdProjectData->salesTaxPercent);
    }

    #[Group('issue-164')]
    #[Group('issue-257')]
    public function testPlanRefusedByTheBomLineLimitIsABadRequestWithTheSameMessage(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->givenSabreHasABlueprint();
        $this->projectService->method('createProject')->willThrowException(
            new \RuntimeException('The production plan has 1001 BOM lines, the limit is 1000.'),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The production plan has 1001 BOM lines, the limit is 1000.');

        $this->processor->process($this->sabreInput(), new Post());
    }

    private function sabreInput(): CreateGroupProjectInput
    {
        $input = new CreateGroupProjectInput();
        $input->name = 'Sabre batch';
        $input->items = [
            ['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
        ];

        return $input;
    }

    private function givenSabreHasABlueprint(): void
    {
        $product = new IndustryActivityProduct();
        $product->setTypeId(self::SABRE_BLUEPRINT_TYPE_ID);
        $product->setActivityId(1);
        $product->setProductTypeId(self::SABRE_TYPE_ID);
        $product->setQuantity(1);
        $this->activityProductRepository->method('findBlueprintForProduct')->willReturn($product);
    }

    private function createProjectFrom(CreateGroupProjectInput $input): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->givenSabreHasABlueprint();
        $this->projectService->method('createProject')->willReturnCallback(
            function (User $owner, CreateProjectData $data): GroupIndustryProject {
                self::assertSame($this->owner, $owner);
                $this->createdProjectData = $data;

                return new GroupIndustryProject();
            },
        );

        $this->processor->process($input, new Post());
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
