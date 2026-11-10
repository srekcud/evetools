<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Delete;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\State\Processor\Pve\DeleteLootSaleProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(DeleteLootSaleProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-533')]
final class DeleteLootSaleProcessorEscalationTest extends TestCase
{
    public function testAnEscalationIncomeCannotBeDeletedAsALootSaleAndIsLeftInPlace(): void
    {
        $pilot = new User();
        $escalationIncome = (new PveIncome())
            ->setUser($pilot)
            ->setType(PveIncomeType::Escalation)
            ->setDescription('Blood Raider Hideaway - Aldranette')
            ->setAmount(100_000_000.0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->createProcessor($pilot, $escalationIncome, $entityManager)->process(null, new Delete(), ['id' => 'a3c1f0e2-5b7d-4e9a-8c21-6d4f0b9e7a15']);
            $this->fail('An escalation income was deleted as a loot sale');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function testALootSaleIncomeIsStillDeleted(): void
    {
        $pilot = new User();
        $lootSale = (new PveIncome())
            ->setUser($pilot)
            ->setType(PveIncomeType::LootSale)
            ->setDescription('6x Rogue Drone Infestation Data')
            ->setAmount(2_460_000.0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($lootSale);

        $this->createProcessor($pilot, $lootSale, $entityManager)->process(null, new Delete(), ['id' => 'a3c1f0e2-5b7d-4e9a-8c21-6d4f0b9e7a15']);
    }

    private function createProcessor(User $pilot, PveIncome $income, EntityManagerInterface $entityManager): DeleteLootSaleProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('find')->willReturn($income);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn(new UserPveSettings());

        return new DeleteLootSaleProcessor($security, $incomeRepository, $settingsRepository, $entityManager);
    }
}
