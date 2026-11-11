<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\CharacterSellOrder;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\CharacterOrdersService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterOrdersService::class)]
#[CoversClass(CharacterSellOrder::class)]
#[Group('issue-350')]
final class CharacterOrdersServiceTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_113_350_001;

    private const array SELL_ROW = [
        'duration' => 90,
        'escrow' => 0.0,
        'is_buy_order' => false,
        'issued' => '2026-11-10T08:30:00Z',
        'location_id' => 60_003_760,
        'min_volume' => 1,
        'order_id' => 6_350_000_001,
        'price' => 4_250_000.0,
        'range' => 'region',
        'region_id' => 10_000_002,
        'type_id' => 2_185,
        'volume_remain' => 4,
        'volume_total' => 10,
    ];

    public function testSellOrderIsReadFromTheCharacterOrdersEndpointWithItsToken(): void
    {
        $character = $this->character();
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/characters/' . self::EVE_CHARACTER_ID . '/orders/', $character->getEveToken())
            ->willReturn([self::SELL_ROW]);

        $orders = (new CharacterOrdersService($esiClient))->getSellOrders($character);

        self::assertEquals(
            [new CharacterSellOrder(6_350_000_001, 2_185, 60_003_760, 4_250_000.0, 4, 10, new \DateTimeImmutable('2026-11-10T08:30:00Z'))],
            $orders,
        );
    }

    public function testBuyOrdersAreIgnoredWhetherTheFlagIsTrueOrAbsent(): void
    {
        $buyOrder = [...self::SELL_ROW, 'order_id' => 6_350_000_002, 'is_buy_order' => true];
        $sellOrderWithoutFlag = self::SELL_ROW;
        unset($sellOrderWithoutFlag['is_buy_order']);
        $sellOrderWithoutFlag['order_id'] = 6_350_000_003;

        $orders = (new CharacterOrdersService($this->clientReturning([$buyOrder, self::SELL_ROW, $sellOrderWithoutFlag])))->getSellOrders($this->character());

        self::assertSame([6_350_000_001, 6_350_000_003], array_map(static fn (CharacterSellOrder $order): int => $order->orderId, $orders));
    }

    public function testCharacterWithoutTokenRequiresAuthentication(): void
    {
        $character = (new Character())->setEveCharacterId(self::EVE_CHARACTER_ID)->setName('Aria Solberg');

        $this->expectException(EveAuthRequiredException::class);

        (new CharacterOrdersService($this->createStub(EsiClient::class)))->getSellOrders($character);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFields(): iterable
    {
        foreach (['order_id', 'type_id', 'location_id', 'price', 'volume_remain', 'volume_total', 'issued'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFields')]
    public function testSellRowWithoutARequiredFieldRaisesAnExplicitError(string $field): void
    {
        $row = self::SELL_ROW;
        unset($row[$field]);

        $this->expectException(EsiApiException::class);
        $this->expectExceptionMessage($field);

        (new CharacterOrdersService($this->clientReturning([$row])))->getSellOrders($this->character());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function clientReturning(array $rows): EsiClient
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn($rows);

        return $esiClient;
    }

    private function character(): Character
    {
        $character = (new Character())->setEveCharacterId(self::EVE_CHARACTER_ID)->setName('Aria Solberg');
        $character->setEveToken((new EveToken())->setScopes(['esi-markets.read_character_orders.v1']));

        return $character;
    }
}
