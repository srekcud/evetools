<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\ApiResource\Input\Industry\Project\CreateProjectInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectTemplate;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Project\CreateProjectData;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectSellStructures;
use App\Service\Industry\Project\SellStructure;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\State\Processor\Industry\Project\CreateProjectProcessor;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(CreateProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class CreateProjectProcessorTest extends TestCase
{
    private const int SABRE_TYPE_ID = 22456;
    private const int SABRE_BLUEPRINT_TYPE_ID = 22457;
    private const float MINIMUM_OUTSIDE_SAVING = 125_000.0;
    private const int JITA_STRUCTURE_ID = 60003760;
    private const int KEEPSTAR_STRUCTURE_ID = 1042000000001;

    private Security&Stub $security;
    private IndustryProjectService&Stub $projectService;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private ProjectResourceMapper&Stub $mapper;
    private IndustryProjectTemplateRepository&Stub $templateRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private CreateProjectProcessor $processor;
    private User&Stub $owner;
    private ?SalesFees $sellerFees = null;
    private ?CreateProjectData $createdProjectData = null;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectService = $this->createStub(IndustryProjectService::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->mapper = $this->createStub(ProjectResourceMapper::class);
        $this->templateRepository = $this->createStub(IndustryProjectTemplateRepository::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->owner = $this->createUser();
        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(
            fn (User $user): ?SalesFees => $user === $this->owner ? $this->sellerFees : null,
        );

        $this->processor = new CreateProjectProcessor(
            $this->security,
            $this->projectService,
            $this->memberRepository,
            $this->mapper,
            $this->invTypeRepository,
            $this->activityProductRepository,
            $salesFeesResolver,
            $this->templateRepository,
            new ProjectSellStructures(),
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

    #[Group('issue-445')]
    public function testCategoryKeysSentAtCreationAreResolvedIntoTheGroupsOfTheBlacklistAfterTheGroupsSent(): void
    {
        $input = $this->sabreInput();
        $input->blacklistGroupIds = [999];
        $input->blacklistTypeIds = [34];
        $input->blacklistCategoryKeys = ['fuel_blocks', 'biochem_reactions'];

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame([999, 1136, 661, 662], $this->createdProjectData->blacklistGroupIds);
        self::assertSame([34], $this->createdProjectData->blacklistTypeIds);
    }

    public static function blacklistUnionProvider(): iterable
    {
        yield 'B1 settings groups and types come after the input ones' => [[1136], [34], [999], [], [35], [999, 1136], [35, 34]];
        yield 'B2 an entry of the input already in the settings is kept once at its input rank' => [[1136], [34], [1136, 999], [], [34], [1136, 999], [34]];
        yield 'B3 without settings the category keys resolve as before' => [[], [], [999], ['fuel_blocks'], [35], [999, 1136], [35]];
        yield 'B4 an empty input gives the settings alone' => [[1136, 484], [34, 35], [], [], [], [1136, 484], [34, 35]];
        yield 'B5 the settings group comes after the groups of the category keys' => [[334], [], [], ['biochem_reactions'], [], [661, 662, 334], []];
    }

    #[Group('issue-575')]
    #[DataProvider('blacklistUnionProvider')]
    public function testBlacklistOfTheProjectIsTheUnionOfTheInputAndTheSettingsOfTheOwner(array $settingsGroupIds, array $settingsTypeIds, array $inputGroupIds, array $inputCategoryKeys, array $inputTypeIds, array $expectedGroupIds, array $expectedTypeIds): void
    {
        $this->owner->method('getIndustryBlacklistGroupIds')->willReturn($settingsGroupIds);
        $this->owner->method('getIndustryBlacklistTypeIds')->willReturn($settingsTypeIds);
        $input = $this->sabreInput();
        $input->blacklistGroupIds = $inputGroupIds;
        $input->blacklistCategoryKeys = $inputCategoryKeys;
        $input->blacklistTypeIds = $inputTypeIds;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame($expectedGroupIds, $this->createdProjectData->blacklistGroupIds);
        self::assertSame($expectedTypeIds, $this->createdProjectData->blacklistTypeIds);
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

    #[Group('issue-341')]
    public function testSelectionPolicySentAtCreationIsPassedToTheService(): void
    {
        $input = $this->sabreInput();
        $input->acceptsOutsideFavoriteSystem = true;
        $input->minimumOutsideSaving = self::MINIMUM_OUTSIDE_SAVING;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame([true, self::MINIMUM_OUTSIDE_SAVING], [$this->createdProjectData->acceptsOutsideFavoriteSystem, $this->createdProjectData->minimumOutsideSaving]);
    }

    #[Group('issue-341')]
    public function testSelectionPolicyAbsentAtCreationIsPassedToTheServiceAsRefusingAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSaving(): void
    {
        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertSame([false, IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING], [$this->createdProjectData->acceptsOutsideFavoriteSystem, $this->createdProjectData->minimumOutsideSaving]);
    }

    private function givenSellerFees(?array $sellerRates): void
    {
        if ($sellerRates === null) {
            return;
        }

        $this->sellerFees = new SalesFees($sellerRates[0], $sellerRates[1]);
    }

    private function sabreInput(): CreateProjectInput
    {
        $input = new CreateProjectInput();
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

    private function createProjectFrom(CreateProjectInput $input): void
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

    #[Group('issue-50')]
    public function testSellStructureAbsentAtCreationIsThePreferredMarketStructureOfTheOwner(): void
    {
        $this->owner->method('getPreferredMarketStructureId')->willReturn(self::JITA_STRUCTURE_ID);
        $this->owner->method('getPreferredMarketStructureName')->willReturn('Jita 4-4');

        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertEquals(new SellStructure(self::JITA_STRUCTURE_ID, 'Jita 4-4'), $this->createdProjectData->sellStructure);
    }

    #[Group('issue-50')]
    public function testSellStructureAbsentAtCreationStaysUnsetWhenTheOwnerHasNoMarketStructure(): void
    {
        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertNull($this->createdProjectData->sellStructure);
    }

    #[Group('issue-50')]
    public function testSellStructureSentAtCreationTakesItsNameFromTheMarketStructuresOfTheOwner(): void
    {
        $this->owner->method('getPreferredMarketStructureId')->willReturn(self::JITA_STRUCTURE_ID);
        $this->owner->method('getPreferredMarketStructureName')->willReturn('Jita 4-4');
        $this->owner->method('getMarketStructures')->willReturn([['id' => self::KEEPSTAR_STRUCTURE_ID, 'name' => 'Keepstar A']]);
        $input = $this->sabreInput();
        $input->sellStructureId = self::KEEPSTAR_STRUCTURE_ID;

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertEquals(new SellStructure(self::KEEPSTAR_STRUCTURE_ID, 'Keepstar A'), $this->createdProjectData->sellStructure);
    }

    #[Group('issue-50')]
    public function testSellStructureSentAtCreationOutsideTheMarketStructuresOfTheOwnerIsABadRequestAndNoProjectIsCreated(): void
    {
        $this->owner->method('getPreferredMarketStructureId')->willReturn(self::JITA_STRUCTURE_ID);
        $input = $this->sabreInput();
        $input->sellStructureId = 999;

        try {
            $this->createProjectFrom($input);
            self::fail('A sell structure the owner does not have must be refused');
        } catch (BadRequestHttpException) {
            self::assertNull($this->createdProjectData);
        }
    }

    #[Group('issue-355')]
    public function testTemplateVisibleToTheUserIsLinkedToTheCreatedProject(): void
    {
        $hammerheadLine = (new IndustryProjectTemplate())->setOwner($this->owner)->setName('Hammerhead line');
        $warriorLine = (new IndustryProjectTemplate())->setOwner($this->owner)->setName('Warrior line');
        $this->templateRepository->method('findVisibleTo')->willReturn([$hammerheadLine, $warriorLine]);
        $input = $this->sabreInput();
        $input->templateId = (string) $warriorLine->getId();

        $this->createProjectFrom($input);

        self::assertNotNull($this->createdProjectData);
        self::assertSame($warriorLine, $this->createdProjectData->template);
    }

    #[Group('issue-355')]
    public function testTemplateNotVisibleToTheUserIsNotFoundAndNoProjectIsCreated(): void
    {
        $aliceWarriorLine = (new IndustryProjectTemplate())->setOwner($this->createUser())->setName('Warrior line');
        $this->templateRepository->method('findVisibleTo')->willReturn([]);
        $input = $this->sabreInput();
        $input->templateId = (string) $aliceWarriorLine->getId();

        try {
            $this->createProjectFrom($input);
            self::fail('A template not visible to the user must be refused');
        } catch (NotFoundHttpException $exception) {
            self::assertSame('Template not found', $exception->getMessage());
        }

        self::assertNull($this->createdProjectData);
    }

    #[Group('issue-355')]
    public function testProjectCreatedWithoutTemplateIdHasNoTemplate(): void
    {
        $hammerheadLine = (new IndustryProjectTemplate())->setOwner($this->owner)->setName('Hammerhead line');
        $this->templateRepository->method('findVisibleTo')->willReturn([$hammerheadLine]);

        $this->createProjectFrom($this->sabreInput());

        self::assertNotNull($this->createdProjectData);
        self::assertNull($this->createdProjectData->template);
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process(new CreateProjectInput(), new Post());
    }

    public function testThrowsBadRequestWhenNoItems(): void
    {
        $this->security->method('getUser')->willReturn($this->createUser());

        $input = new CreateProjectInput();
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

        $expectedResource = new IndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateProjectInput();
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

        $input = new CreateProjectInput();
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

        $input = new CreateProjectInput();
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

        $input = new CreateProjectInput();
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

        $expectedResource = new IndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateProjectInput();
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

        $expectedResource = new IndustryProjectResource();
        $this->mapper->method('projectToResource')->willReturn($expectedResource);

        $input = new CreateProjectInput();
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
