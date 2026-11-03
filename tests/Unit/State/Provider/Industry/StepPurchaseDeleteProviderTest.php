<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\StepPurchaseResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\StepPurchaseDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StepPurchaseDeleteProvider::class)]
#[Group('issue-200')]
#[Group('issue-206')]
final class StepPurchaseDeleteProviderTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000001';
    private const string STEP_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000011';
    private const string PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000021';
    private const int ISOTOPE_TYPE_ID = 16274;
    private const int PURCHASED_ISOTOPES = 2_160;
    private const float ISOTOPE_UNIT_PRICE = 480.0;

    public function testPurchaseIdThatIsNotAUuidIsNotFoundAfterReadingTheProjectAndTheStep(): void
    {
        $user = new User();
        $project = (new IndustryProject())->setUser($user);
        $step = (new IndustryProjectStep())->setProject($project);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->once())->method('find')->willReturn($project);
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->once())->method('find')->willReturn($step);
        $purchaseRepository = $this->createMock(IndustryStepPurchaseRepository::class);
        $purchaseRepository->expects($this->never())->method('find');
        $provider = new StepPurchaseDeleteProvider(
            $security,
            $projectRepository,
            $stepRepository,
            $purchaseRepository,
            $this->createStub(IndustryResourceMapper::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Purchase not found');

        $provider->provide(new Delete(), ['id' => self::PROJECT_ID, 'stepId' => self::STEP_ID, 'purchaseId' => 'p-77']);
    }

    public function testPurchaseOfTheStepIsProvidedAsItsResourceAndStaysOnTheStep(): void
    {
        $user = new User();
        $project = (new IndustryProject())->setUser($user);
        $step = (new IndustryProjectStep())->setProject($project);
        $isotopePurchase = (new IndustryStepPurchase())
            ->setTypeId(self::ISOTOPE_TYPE_ID)
            ->setQuantity(self::PURCHASED_ISOTOPES)
            ->setUnitPrice(self::ISOTOPE_UNIT_PRICE)
            ->setTotalPrice(self::PURCHASED_ISOTOPES * self::ISOTOPE_UNIT_PRICE)
            ->setSource('manual');
        $step->addPurchase($isotopePurchase);
        $isotopePurchaseResource = new StepPurchaseResource();
        $isotopePurchaseResource->id = self::PURCHASE_ID;
        $isotopePurchaseResource->quantity = self::PURCHASED_ISOTOPES;
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $purchaseRepository = $this->createStub(IndustryStepPurchaseRepository::class);
        $purchaseRepository->method('find')->willReturn($isotopePurchase);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('purchaseToResource')->willReturnMap([[$isotopePurchase, $isotopePurchaseResource]]);
        $provider = new StepPurchaseDeleteProvider($security, $projectRepository, $stepRepository, $purchaseRepository, $mapper);

        $providedResource = $provider->provide(new Delete(), ['id' => self::PROJECT_ID, 'stepId' => self::STEP_ID, 'purchaseId' => self::PURCHASE_ID]);

        self::assertSame(
            [$isotopePurchaseResource, [[self::ISOTOPE_TYPE_ID, self::PURCHASED_ISOTOPES, 1_036_800.0]]],
            [$providedResource, array_map(
                static fn (IndustryStepPurchase $purchase): array => [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getTotalPrice()],
                $step->getPurchases()->toArray(),
            )],
        );
    }
}
