<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectSale;
use App\Enum\SaleChannel;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-500')]
final class ProjectContractSalesApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int FINISHED_ISHTAR_CONTRACT_ID = 601_001;
    private const int COURIER_CONTRACT_ID = 601_002;
    private const int OUTSTANDING_CONTRACT_ID = 601_003;
    private const int TWO_TYPES_CONTRACT_ID = 601_004;
    private const int OTHER_PRODUCT_CONTRACT_ID = 601_005;
    private const int BEFORE_THE_PROJECT_CONTRACT_ID = 601_006;
    private const int BOUGHT_BY_THE_OWNER_CONTRACT_ID = 601_007;
    private const int OWNER_EVE_CHARACTER_ID = 2_500_000_001;
    private const int OTHER_EVE_CHARACTER_ID = 2_500_000_002;
    private const float ISHTAR_CONTRACT_PRICE = 3_000_000.0;
    private const float BROKER_FEE = 30_000.0;
    private const float SALES_TAX = 108_000.0;

    private EsiClient&Stub $esiClient;
    private Character $unreadableCharacter;
    /** @var array<int, list<array<string, mixed>>> */
    private array $contractsByEveCharacterId = [];
    /** @var array<int, list<array<string, mixed>>> */
    private array $itemsByContractId = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->ishtarBatch->addItem((new IndustryProjectProduct())->setTypeId(self::ISHTAR_TYPE_ID)->setTypeName('Ishtar')->setRuns(self::ISHTAR_RUNS));
        $ownerCharacter = $this->owner->getMainCharacter();
        $ownerCharacter->setEveCharacterId(self::OWNER_EVE_CHARACTER_ID)->setEveToken((new EveToken())
            ->setAccessToken('access-owner')
            ->setRefreshTokenEncrypted('refresh-owner')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-contracts.read_character_contracts.v1']));
        $this->unreadableCharacter = (new Character())->setEveCharacterId(self::OTHER_EVE_CHARACTER_ID)->setName('Aria Alt')->setCorporationId(self::OWNER_CORPORATION_ID)->setCorporationName('Corporation Alt');
        $this->owner->addCharacter($this->unreadableCharacter);
        $this->em->persist($this->unreadableCharacter);
        $this->em->flush();

        $this->contractsByEveCharacterId[self::OWNER_EVE_CHARACTER_ID] = [
            $this->contract(self::FINISHED_ISHTAR_CONTRACT_ID, 'item_exchange', 'finished', '+5 minutes', self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::COURIER_CONTRACT_ID, 'courier', 'finished', '+5 minutes', self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::OUTSTANDING_CONTRACT_ID, 'item_exchange', 'outstanding', null, self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::TWO_TYPES_CONTRACT_ID, 'item_exchange', 'finished', '+5 minutes', self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::OTHER_PRODUCT_CONTRACT_ID, 'item_exchange', 'finished', '+5 minutes', self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::BEFORE_THE_PROJECT_CONTRACT_ID, 'item_exchange', 'finished', '-2 days', self::OWNER_EVE_CHARACTER_ID),
            $this->contract(self::BOUGHT_BY_THE_OWNER_CONTRACT_ID, 'item_exchange', 'finished', '+5 minutes', 2_999_999_999),
        ];
        $this->itemsByContractId = [
            self::FINISHED_ISHTAR_CONTRACT_ID => [$this->item(self::ISHTAR_TYPE_ID, 1), $this->item(self::ISHTAR_TYPE_ID, 1)],
            self::TWO_TYPES_CONTRACT_ID => [$this->item(self::ISHTAR_TYPE_ID, 1), $this->item(self::HECATE_TYPE_ID, 1)],
            self::OTHER_PRODUCT_CONTRACT_ID => [$this->item(self::HECATE_TYPE_ID, 1)],
            self::BEFORE_THE_PROJECT_CONTRACT_ID => [$this->item(self::ISHTAR_TYPE_ID, 2)],
            self::BOUGHT_BY_THE_OWNER_CONTRACT_ID => [$this->item(self::ISHTAR_TYPE_ID, 2)],
        ];
        $this->replaceEsi();
    }

    public function testOwnerReadsTheFinishedContractsThatSoldAnItemOfTheProjectAndTheCharactersItCouldNotRead(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->suggestionsUri());

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(
            [[
                'contractId' => self::FINISHED_ISHTAR_CONTRACT_ID,
                'typeId' => self::ISHTAR_TYPE_ID,
                'typeName' => 'Ishtar',
                'quantity' => 2,
                'price' => self::ISHTAR_CONTRACT_PRICE,
                'unitPrice' => 1_500_000.0,
                'sellerCharacterId' => self::OWNER_EVE_CHARACTER_ID,
                'sellerCharacterName' => 'Aria Solberg',
                'contractFees' => null,
            ]],
            array_map(static function (array $candidate): array {
                unset($candidate['completedAt']);

                return $candidate;
            }, $body['candidates']),
        );
        self::assertSame([['characterId' => self::OTHER_EVE_CHARACTER_ID, 'reason' => 'no_token']], $body['unreadableCharacters']);
    }

    public function testFeesOfTheContractAreTheBrokerFeeAndTheSalesTaxOfTheJournalWhenItWasRead(): void
    {
        $this->storeJournalFee('contract_brokers_fee', -self::BROKER_FEE, self::FINISHED_ISHTAR_CONTRACT_ID);
        $this->storeJournalFee('contract_sales_tax', -self::SALES_TAX, self::FINISHED_ISHTAR_CONTRACT_ID);
        $this->storeJournalFee('contract_sales_tax', -999_999.0, self::COURIER_CONTRACT_ID);

        $this->actAs($this->owner);
        $this->request('GET', $this->suggestionsUri());

        self::assertSame([self::BROKER_FEE + self::SALES_TAX], array_column($this->responseJson()['candidates'], 'contractFees'));
    }

    public function testAContractAlreadyLinkedToASaleIsNoLongerProposed(): void
    {
        $this->linkContractDirectly(self::FINISHED_ISHTAR_CONTRACT_ID);

        $this->actAs($this->owner);
        $this->request('GET', $this->suggestionsUri());

        self::assertSame([], $this->responseJson()['candidates']);
    }

    public function testPlainMemberIsRefusedTheSuggestionsAndTheLinking(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->suggestionsUri());
        self::assertResponseStatusCodeSame(403);

        $this->actAs($this->member);
        $this->request('POST', $this->linkUri(), ['contractId' => self::FINISHED_ISHTAR_CONTRACT_ID, 'characterId' => self::OWNER_EVE_CHARACTER_ID]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contractSalesCount());
    }

    public function testOwnerLinksTheContractAndTheSaleIsFrozenOnItsQuantityPriceDateAndSeller(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::FINISHED_ISHTAR_CONTRACT_ID, 'characterId' => self::OWNER_EVE_CHARACTER_ID]);

        self::assertResponseStatusCodeSame(201);
        $sale = $this->responseJson();
        self::assertSame(
            ['contract', self::FINISHED_ISHTAR_CONTRACT_ID, self::ISHTAR_TYPE_ID, 2, 1_500_000.0, self::ISHTAR_CONTRACT_PRICE, self::OWNER_EVE_CHARACTER_ID, null],
            [$sale['channel'], $sale['contractId'], $sale['typeId'], $sale['quantity'], $sale['unitPrice'], $sale['totalPrice'], $sale['sellerCharacterId'], $sale['contractFees'] ?? null],
        );
        self::assertSame(1, $this->contractSalesCount());
    }

    public function testLinkingTheSameContractTwiceIsAConflict(): void
    {
        $this->linkContractDirectly(self::FINISHED_ISHTAR_CONTRACT_ID);

        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::FINISHED_ISHTAR_CONTRACT_ID, 'characterId' => self::OWNER_EVE_CHARACTER_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->contractSalesCount());
    }

    public function testContractThatIsNotACandidateIsNotFound(): void
    {
        foreach ([self::COURIER_CONTRACT_ID, self::TWO_TYPES_CONTRACT_ID, self::OTHER_PRODUCT_CONTRACT_ID, self::BEFORE_THE_PROJECT_CONTRACT_ID, self::BOUGHT_BY_THE_OWNER_CONTRACT_ID, 999_999] as $contractId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->linkUri(), ['contractId' => $contractId, 'characterId' => self::OWNER_EVE_CHARACTER_ID]);

            self::assertResponseStatusCodeSame(404, sprintf('contract %d', $contractId));
        }
        self::assertSame(0, $this->contractSalesCount());
    }

    public function testBodyWithoutAContractIdIsRejected(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['characterId' => self::OWNER_EVE_CHARACTER_ID]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSalesListExposesTheContractAndItsFeesOnceTheJournalWasRead(): void
    {
        $this->linkContractDirectly(self::FINISHED_ISHTAR_CONTRACT_ID);
        $this->storeJournalFee('contract_brokers_fee', -self::BROKER_FEE, self::FINISHED_ISHTAR_CONTRACT_ID);
        $this->storeJournalFee('contract_sales_tax', -self::SALES_TAX, self::FINISHED_ISHTAR_CONTRACT_ID);

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/sales');

        $contractSales = array_values(array_filter($this->responseJson(), static fn (array $sale): bool => $sale['channel'] === 'contract'));
        self::assertSame(
            [[self::FINISHED_ISHTAR_CONTRACT_ID, self::BROKER_FEE + self::SALES_TAX]],
            array_map(static fn (array $sale): array => [$sale['contractId'], $sale['contractFees']], $contractSales),
        );
    }

    private function suggestionsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/contract-suggestions';
    }

    private function linkUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/sales/from-contract';
    }

    private function contractSalesCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryProjectSale::class)->count(['channel' => SaleChannel::Contract]);
    }

    private function linkContractDirectly(int $contractId): void
    {
        $sale = $this->addSale($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 2, 1_500_000.0, 'Contract', '+5 minutes')
            ->setChannel(SaleChannel::Contract)
            ->setContractId($contractId)
            ->setSeller($this->owner->getMainCharacter());
        $this->em->persist($sale);
        $this->em->flush();
    }

    private function storeJournalFee(string $refType, float $amount, int $contractId): void
    {
        $this->em->persist((new CachedWalletJournalEntry())
            ->setCharacter($this->owner->getMainCharacter())
            ->setJournalEntryId(random_int(1, PHP_INT_MAX))
            ->setRefType($refType)
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable('+5 minutes'))
            ->setContextId($contractId)
            ->setContextIdType('contract_id'));
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(int $contractId, string $type, string $status, ?string $completedAt, int $issuerId): array
    {
        $contract = [
            'contract_id' => $contractId,
            'type' => $type,
            'status' => $status,
            'issuer_id' => $issuerId,
            'date_issued' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:sP'),
            'price' => self::ISHTAR_CONTRACT_PRICE,
        ];
        if ($completedAt !== null) {
            $contract['date_completed'] = (new \DateTimeImmutable($completedAt))->format('Y-m-d\TH:i:sP');
        }

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $typeId, int $quantity): array
    {
        return ['type_id' => $typeId, 'quantity' => $quantity, 'is_included' => true, 'record_id' => random_int(1, PHP_INT_MAX)];
    }

    private function replaceEsi(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint): array {
            preg_match('#^/characters/(\d+)/contracts/$#', $endpoint, $matches);

            return $this->contractsByEveCharacterId[(int) ($matches[1] ?? 0)] ?? [];
        });
        $this->esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            preg_match('#^/characters/\d+/contracts/(\d+)/items/$#', $endpoint, $matches);

            return $this->itemsByContractId[(int) ($matches[1] ?? 0)] ?? [];
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        self::getContainer()->set(EsiClient::class, $this->esiClient);
        self::getContainer()->set(TokenManager::class, $tokenManager);
    }
}
