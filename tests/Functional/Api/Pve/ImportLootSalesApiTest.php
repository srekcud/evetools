<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Pve;

use App\Entity\Character;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-243')]
final class ImportLootSalesApiTest extends WebTestCase
{
    private const int MAXIMUM_SALES = 500;
    private const int EVE_CHARACTER_ID = 2_112_243_001;
    private const int DECLINED_TRANSACTION_ID = 8_200_023;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Selka Vorr')
            ->setCorporationId(98_243_001)
            ->setCorporationName('Vorr Salvage Collective');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $this->em->persist($this->pilot);
        $this->em->persist($character);
        $this->em->flush();
        $this->client->loginUser($this->pilot, 'api');
    }

    public function testValidSalesAreImported(): void
    {
        $this->postImport([$this->marketLootSale(8_200_031, 2_460_000.0), $this->marketLootSale(8_200_032, 1_230_000.0)]);

        self::assertResponseIsSuccessful();
        self::assertSame([2_460_000.0, 1_230_000.0], $this->importedAmounts());
    }

    public function testSaleWithAZeroPriceBesideAValidSaleIsRejectedOnItsPriceAndNothingIsWritten(): void
    {
        $this->postImport(
            [$this->marketLootSale(8_200_031, 2_460_000.0), $this->marketLootSale(8_200_032, 0)],
            [['transactionId' => self::DECLINED_TRANSACTION_ID]],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sales[1][price]'], $this->violatedPaths());
        self::assertSame([[], null], [$this->importedAmounts(), $this->settingsOfThePilot()]);
    }

    public function testFiveHundredAndOneSalesAreRejectedOnTheSalesAndNothingIsWritten(): void
    {
        $this->postImport(array_map(
            fn (int $index): array => $this->marketLootSale(8_300_000 + $index, 410_000.0),
            range(1, self::MAXIMUM_SALES + 1),
        ));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sales'], $this->violatedPaths());
        self::assertSame([], $this->importedAmounts());
    }

    private function marketLootSale(int $transactionId, int|float $price): array
    {
        return [
            'type' => 'loot_sale',
            'typeId' => 60459,
            'typeName' => '6x Rogue Drone Infestation Data',
            'quantity' => 6,
            'price' => $price,
            'dateIssued' => '2026-10-28',
            'transactionId' => $transactionId,
        ];
    }

    private function postImport(array $sales, array $declined = []): void
    {
        $this->client->request(
            'POST',
            '/api/pve/import-loot-sales',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['sales' => $sales, 'declined' => $declined], JSON_THROW_ON_ERROR),
        );
    }

    private function violatedPaths(): array
    {
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_unique(array_column($response['violations'] ?? [], 'propertyPath')));
    }

    private function importedAmounts(): array
    {
        $this->em->clear();

        return array_map(
            static fn (PveIncome $income): float => $income->getAmount(),
            $this->em->getRepository(PveIncome::class)->findBy(['user' => $this->pilot->getId()], ['transactionId' => 'ASC']),
        );
    }

    private function settingsOfThePilot(): ?UserPveSettings
    {
        return $this->em->getRepository(UserPveSettings::class)->findOneBy(['user' => $this->pilot->getId()]);
    }
}
