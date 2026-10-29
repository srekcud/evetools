<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStepPurchaseRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\StepPurchaseDeleteProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(StepPurchaseDeleteProvider::class)]
#[Group('issue-200')]
final class StepPurchaseDeleteProviderTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000001';
    private const string STEP_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000011';

    public function testPurchaseIdThatIsNotAUuidIsNotFoundAfterReadingTheProjectAndTheStepWithoutRemovingAnything(): void
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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $provider = new StepPurchaseDeleteProvider(
            $security,
            $projectRepository,
            $stepRepository,
            $purchaseRepository,
            $this->createStub(IndustryResourceMapper::class),
            $entityManager,
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Purchase not found');

        $provider->provide(new Delete(), ['id' => self::PROJECT_ID, 'stepId' => self::STEP_ID, 'purchaseId' => 'p-77']);
    }
}
