<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\User;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-348')]
final class SellMarketApiTest extends WebTestCase
{
    use SeedsJitaOrderBooksAndCostIndices;

    private const int EVE_CHARACTER_ID = 2_112_348_001;
    private const int ORDERED_QUANTITY = 50;
    private const float CHEAPEST_SELL_PRICE = 760_000.0;
    private const int CHEAPEST_SELL_VOLUME = 30;
    private const float NEXT_SELL_PRICE = 745_000.0;
    private const int NEXT_SELL_VOLUME = 1_000;

    private KernelBrowser $client;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->recordEsiRequestsInsteadOfSendingThem();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        ManufacturableProductSde::persist($em);
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Vessa Harrow')
            ->setCorporationId(98_348_001)
            ->setCorporationName('Harrow Hull Works');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $em->persist($this->pilot);
        $em->persist($character);
        $em->flush();
    }

    public function testPilotWithoutPreferredStructureGetsTheJitaRowAndAnUnknownImmediateSale(): void
    {
        $this->seedJitaSellOrderBooks([
            ManufacturableProductSde::FEROX => [
                ['price' => self::CHEAPEST_SELL_PRICE, 'volume' => self::CHEAPEST_SELL_VOLUME],
                ['price' => self::NEXT_SELL_PRICE, 'volume' => self::NEXT_SELL_VOLUME],
            ],
        ]);
        $this->seedNoJitaSellOrderFor(ManufacturableProductSde::FEROX);

        $this->client->loginUser($this->pilot, 'api');
        $this->requestSellMarkets('?quantity='.self::ORDERED_QUANTITY);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $weightedPrice = (self::CHEAPEST_SELL_VOLUME * self::CHEAPEST_SELL_PRICE + 20 * self::NEXT_SELL_PRICE) / self::ORDERED_QUANTITY;
        self::assertSame(ManufacturableProductSde::FEROX, $body['typeId']);
        self::assertSame(ManufacturableProductSde::FEROX_NAME, $body['typeName']);
        self::assertSame(self::ORDERED_QUANTITY, $body['quantity']);
        self::assertSame(0.1, $body['sellShareOfDailyVolume']);
        self::assertCount(1, $body['markets']);
        self::assertSame('jita', $body['markets'][0]['key']);
        self::assertSame('Jita IV - Moon 4', $body['markets'][0]['name']);
        self::assertEqualsWithDelta($weightedPrice, $body['markets'][0]['unitPrice'], 0.01);
        self::assertNull($body['markets'][0]['coveragePercent']);
        self::assertSame('region_history', $body['markets'][0]['volumeKind']);
        self::assertNull($body['markets'][0]['feeRate']);
        self::assertNull($body['markets'][0]['netUnitPrice']);
        self::assertSame(['averageUnitPrice' => null, 'coveragePercent' => null, 'netUnitPrice' => null], $body['immediateSale']);
    }

    public function testQuantityIsRequired(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->requestSellMarkets('');

        self::assertResponseStatusCodeSame(400);
    }

    private function requestSellMarkets(string $query): void
    {
        $this->client->request('GET', '/api/industry/sell-markets/'.ManufacturableProductSde::FEROX.$query, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
