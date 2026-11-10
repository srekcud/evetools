<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\State\Provider\Pve\LootSaleCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LootSaleCollectionProvider::class)]
#[Group('issue-533')]
final class LootSaleCollectionProviderEscalationTest extends TestCase
{
    public function testEscalationIncomeIsNotListedAmongTheLootSales(): void
    {
        $incomes = [
            $this->income(PveIncomeType::LootSale, '6x Rogue Drone Infestation Data', 2_460_000.0, '2026-11-04'),
            $this->income(PveIncomeType::Escalation, 'Blood Raider Hideaway - Aldranette', 100_000_000.0, '2026-11-03'),
            $this->income(PveIncomeType::LootContract, 'Contract 91 - Overseer Effects', 1_230_000.0, '2026-11-02'),
            $this->income(PveIncomeType::Bounty, 'Bounty - Selka Vorr', 5_000_000.0, '2026-11-01'),
        ];

        $lootSales = $this->createProvider($incomes)->provide(new GetCollection());

        $this->assertSame(
            [['loot_sale', 2_460_000.0], ['loot_contract', 1_230_000.0], ['bounty', 5_000_000.0]],
            array_map(static fn ($lootSale): array => [$lootSale->type, $lootSale->amount], $lootSales),
        );
    }

    public function testListingOnlyEscalationIncomeGivesAnEmptyList(): void
    {
        $lootSales = $this->createProvider([
            $this->income(PveIncomeType::Escalation, 'Guristas Den - Tamo', 80_000_000.0, '2026-11-05'),
        ])->provide(new GetCollection());

        $this->assertSame([], $lootSales);
    }

    private function income(PveIncomeType $type, string $description, float $amount, string $date): PveIncome
    {
        return (new PveIncome())
            ->setType($type)
            ->setDescription($description)
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable($date));
    }

    private function createProvider(array $incomes): LootSaleCollectionProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('findByUserAndDateRange')->willReturn($incomes);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new LootSaleCollectionProvider($security, $incomeRepository, $requestStack);
    }
}
