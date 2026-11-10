<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Escalation;

use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-533')]
final class EscalationIncomeLootSaleGuardsApiTest extends EscalationIncomeApiTestCase
{
    public function testLootSalesListDoesNotContainTheEscalationIncome(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $this->incomeOf($this->pilot, PveIncomeType::LootSale, 2_460_000.0, $this->saleDate, '6x Rogue Drone Infestation Data');

        $lootSales = $this->getJson($this->pilot, '/api/pve/loot-sales?days=30');

        self::assertSame(
            [['loot_sale', 2_460_000.0, '6x Rogue Drone Infestation Data']],
            array_map(static fn (array $lootSale): array => [$lootSale['type'], $lootSale['amount'], $lootSale['description']], $lootSales),
        );
    }

    public function testDeletingAnEscalationIncomeAsALootSaleIsAConflictAndKeepsTheIncome(): void
    {
        $income = $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $incomeId = $income->getId()?->toRfc4122();

        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('DELETE', '/api/pve/loot-sales/'.$incomeId, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(409);
        $incomes = $this->escalationIncomesOf($this->pilot);
        self::assertCount(1, $incomes);
        self::assertSame(100_000_000.0, $incomes[0]->getAmount());
    }

    public function testCreatingALootSaleOfTypeEscalationIsRejectedAndNothingIsWritten(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request(
            'POST',
            '/api/pve/loot-sales',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['description' => 'Blood Raider Hideaway - Aldranette', 'amount' => 100_000_000.0, 'type' => 'escalation', 'date' => '2026-11-07'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allIncomesOf($this->pilot));
    }

    public function testImportingALootSaleOfTypeEscalationIsRejectedAndNothingIsWritten(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request(
            'POST',
            '/api/pve/import-loot-sales',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode([
                'sales' => [[
                    'type' => 'escalation',
                    'typeName' => 'Blood Raider Hideaway - Aldranette',
                    'price' => 100_000_000.0,
                    'dateIssued' => '2026-11-07',
                    'transactionId' => 8_533_001,
                ]],
                'declined' => [],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allIncomesOf($this->pilot));
    }

    private function allIncomesOf(User $owner): array
    {
        $this->em->clear();

        return $this->em->getRepository(PveIncome::class)->findBy(['user' => $owner->getId()]);
    }
}
