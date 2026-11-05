<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Input\GroupIndustry\CreateGroupProjectInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
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
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private GroupIndustryResourceMapper&Stub $mapper;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private CreateGroupProjectProcessor $processor;
    private User&Stub $owner;
    private ?SalesFees $sellerFees = null;
    private ?CreateProjectData $createdProjectData = null;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectService = $this->createStub(GroupIndustryProjectService::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->mapper = $this->createStub(GroupIndustryResourceMapper::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->owner = $this->createUser();
        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(
            fn (User $user): ?SalesFees => $user === $this->owner ? $this->sellerFees : null,
        );

        $this->processor = new CreateGroupProjectProcessor(
            $this->security,
            $this->projectService,
            $this->memberRepository,
            $this->mapper,
            $this->invTypeRepository,
            $this->activityProductRepository,
            $salesFeesResolver,
        );
    }

    public static function projectFeesProvider(): iterable
    {
        yield 'F1 unknown seller fees and no fee sent give no fee' => [null, null, null, null, null];
        yield 'F2 seller fees give the fee of each absent fee' => [[0.015, 0.045], null, null, 1.5, 4.5];
        yield 'F3 seller fees at 0.036 give exactly 3.6 percent' => [[0.036, 0.036], null, null, 3.6, 3.6];
        yield 'F4 fees sent are kept over the seller fees, zero included' => [[0.015, 0.045], 2.0, 0.0, 2.0, 0.0];
        yield 'F5 a broker fee rate of zero of the seller is a fee' => [[0.0, 0.036], null, null, 0.0, 3.6];
        yield 'F6 only the sales tax sent, the broker fee comes from the seller fees' => [[0.014234, 0.045], null, 3.0, 1.4234, 3.0];
        yield 'F7 a broker fee of zero sent with unknown seller fees is kept, the sales tax stays unknown' => [null, 0.0, null, 0.0, null];
        yield 'F8 only the broker fee sent, the sales tax comes from the seller fees' => [[0.015, 0.045], 2.0, null, 2.0, 4.5];
    }

    #[Group('issue-170')]
    #[Group('issue-347')]
    #[DataProvider('projectFeesProvider')]
    public function testFeeAbsentAtCreationTakesTheSellerFeesOfTheOwnerInPercent(?array $sellerRates, ?float $brokerFeePercentSent, ?float $salesTaxPercentSent, ?float $expectedBrokerFeePercent, ?float $expectedSalesTaxPercent): void
    {
        $this->givenSellerFees($sellerRates);
        $input = $this->sabreInput();
        $input->brokerFeePercent = $brokerFeePercentSent;
        $input->salesTaxPercent = $salesTaxPercentSent;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame($expectedBrokerFeePercent, $this->createdProjectData->brokerFeePercent);
        self::assertSame($expectedSalesTaxPercent, $this->createdProjectData->salesTaxPercent);
    }

    #[Group('issue-347')]
    public function testSellerFeesOfFiveAccountingFiveBrokerRelationsAndStandingsGiveTheProjectFeesInPercent(): void
    {
        $this->givenSellerFees([0.0138, 0.03375]);

        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertSame(1.38, $this->createdProjectData->brokerFeePercent);
        self::assertSame(3.375, $this->createdProjectData->salesTaxPercent);
    }

    #[Group('issue-347')]
    public function testEffectiveFeesAreNotRejectedWhenOneOfThemIsUnknown(): void
    {
        $input = $this->sabreInput();
        $input->brokerFeePercent = 100.0;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame([100.0, null], [$this->createdProjectData->brokerFeePercent, $this->createdProjectData->salesTaxPercent]);
    }

    #[Group('issue-164')]
    #[Group('issue-257')]
    public function testPlanRefusedByTheBomLineLimitIsABadRequestWithTheSameMessage(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->givenSabreHasABlueprint();
        $this->projectService->method('createProject')->willThrowException(
            new \RuntimeException('The production plan has 5001 BOM lines, above the limit of 5000 BOM lines per project.'),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The production plan has 5001 BOM lines, above the limit of 5000 BOM lines per project.');

        $this->processor->process($this->sabreInput(), new Post());
    }

    public static function effectiveFeesOverOneHundredProvider(): iterable
    {
        yield 'F9 broker fee of one hundred sent, sales tax of 3.6 from the seller fees' => [[0.015, 0.036], 100.0, null];
        yield 'broker fee of 96.41 sent, sales tax of 3.6 from the seller fees' => [[0.015, 0.036], 96.41, null];
        yield 'sales tax of 98.51 sent, broker fee of 1.5 from the seller fees' => [[0.015, 0.045], null, 98.51];
    }

    #[Group('issue-257')]
    #[DataProvider('effectiveFeesOverOneHundredProvider')]
    public function testEffectiveFeesSummingOverOneHundredAreRejectedOnTheSalesTaxAndNoProjectIsCreated(?array $sellerRates, ?float $brokerFeePercentSent, ?float $salesTaxPercentSent): void
    {
        $this->givenSellerFees($sellerRates);
        $input = $this->sabreInput();
        $input->brokerFeePercent = $brokerFeePercentSent;
        $input->salesTaxPercent = $salesTaxPercentSent;

        $refusal = null;
        try {
            $this->createProjectFrom($input);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(ValidationException::class, $refusal);
        self::assertSame(422, $refusal->getStatusCode());
        self::assertCount(1, $refusal->getConstraintViolationList());
        self::assertSame('salesTaxPercent', $refusal->getConstraintViolationList()->get(0)->getPropertyPath());
        self::assertNull($this->createdProjectData);
    }

    public static function effectiveFeesOfExactlyOneHundredProvider(): iterable
    {
        yield 'broker fee of 96.4 sent, sales tax of 3.6 from the seller fees' => [[0.015, 0.036], 96.4, null, 96.4, 3.6];
        yield 'sales tax of 98.5 sent, broker fee of 1.5 from the seller fees' => [[0.015, 0.045], null, 98.5, 1.5, 98.5];
    }

    #[Group('issue-257')]
    #[DataProvider('effectiveFeesOfExactlyOneHundredProvider')]
    public function testEffectiveFeesSummingToExactlyOneHundredCreateTheProject(?array $sellerRates, ?float $brokerFeePercentSent, ?float $salesTaxPercentSent, float $expectedBrokerFeePercent, float $expectedSalesTaxPercent): void
    {
        $this->givenSellerFees($sellerRates);
        $input = $this->sabreInput();
        $input->brokerFeePercent = $brokerFeePercentSent;
        $input->salesTaxPercent = $salesTaxPercentSent;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame(
            [$expectedBrokerFeePercent, $expectedSalesTaxPercent],
            [$this->createdProjectData->brokerFeePercent, $this->createdProjectData->salesTaxPercent],
        );
    }

    #[Group('issue-362')]
    public function testPrivateAbsentAtCreationIsPassedToTheServiceAsPrivate(): void
    {
        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertTrue($this->createdProjectData->private);
    }

    #[Group('issue-362')]
    public function testPrivateFalseSentAtCreationIsPassedToTheServiceAsNotPrivate(): void
    {
        $input = $this->sabreInput();
        $input->private = false;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertFalse($this->createdProjectData->private);
    }

    private function givenSellerFees(?array $sellerRates): void
    {
        if ($sellerRates === null) {
            return;
        }

        $this->sellerFees = new SalesFees($sellerRates[0], $sellerRates[1]);
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
            function (User $owner, CreateProjectData $data): IndustryProject {
                self::assertSame($this->owner, $owner);
                $this->createdProjectData = $data;

                return (new IndustryProject())->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1));
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

        $project = $this->createStub(IndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(IndustryProjectParticipant::class);
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

        $project = $this->createStub(IndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(IndustryProjectParticipant::class);
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

        $project = $this->createStub(IndustryProject::class);
        $this->projectService->method('createProject')->willReturn($project);

        $ownerMember = $this->createStub(IndustryProjectParticipant::class);
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
