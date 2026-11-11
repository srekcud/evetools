<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\OrderPositionState;
use App\Dto\OrderPosition;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-350')]
final class ProjectSellOrdersApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_STATION_ID = 60_003_760;
    private const int RIFTER_TYPE_ID = 587;
    private const string ORDER_ISSUED_AT = '2026-11-10 08:00:00';
    private const string ORDER_SYNCED_AT = '2026-11-10 12:15:00';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->hecateBatch->setPrivate(true);
        $this->em->flush();
    }

    public function testAcceptedMemberReadsOnlyHisOwnOrdersOfTheProjectProducts(): void
    {
        $this->persistOrder(1, $this->mainOf($this->member), self::ISHTAR_TYPE_ID, new OrderPosition(OrderPositionState::Undercut, 3, 900.0, null, 800.0), '2026-11-10 12:00:00');
        $this->persistOrder(2, $this->mainOf($this->member), self::RIFTER_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 500.0, null, null), null);
        $this->persistOrder(3, $this->mainOf($this->owner), self::ISHTAR_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 1000.0, null, null), null);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch) . '/sell-orders');

        self::assertResponseStatusCodeSame(200);
        $sellOrders = $this->responseJson();
        self::assertSame(
            [[
                'orderId' => 1,
                'characterName' => $this->mainOf($this->member)->getName(),
                'typeId' => self::ISHTAR_TYPE_ID,
                'typeName' => 'Ishtar',
                'volumeRemain' => 4,
                'locationId' => self::JITA_STATION_ID,
                'marketName' => 'Jita IV - Moon 4',
                'price' => 1000.0,
                'bestPrice' => 900.0,
                'rank' => 3,
                'state' => 'undercut',
                'unknownReason' => null,
                'undercutSince' => (new \DateTimeImmutable('2026-11-10 12:00:00'))->format(\DATE_ATOM),
                'suggestedPrice' => 800.0,
            ]],
            $sellOrders['orders'],
        );
        self::assertSame(
            [[$this->mainOf($this->member)->getName()], (new \DateTimeImmutable(self::ORDER_SYNCED_AT))->format(\DATE_ATOM)],
            [$sellOrders['charactersToReconnect'], $sellOrders['syncedAt']],
        );
    }

    public function testOutsiderIsRefusedTheSellOrders(): void
    {
        $this->persistOrder(1, $this->mainOf($this->outsider), self::ISHTAR_TYPE_ID, new OrderPosition(OrderPositionState::First, 1, 1000.0, null, null), null);
        $this->em->flush();

        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch) . '/sell-orders');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        self::assertArrayNotHasKey('orders', $this->responseJson());
    }

    public function testParticipantOfAnotherProjectIsRefusedThePrivateProjectSellOrders(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->hecateBatch) . '/sell-orders');

        self::assertResponseStatusCodeSame(403);
    }

    private function mainOf(User $user): Character
    {
        return $user->getMainCharacter() ?? throw new \LogicException('The test pilot has no main character');
    }

    private function persistOrder(int $orderId, Character $character, int $typeId, OrderPosition $position, ?string $undercutSince): void
    {
        $this->em->persist(
            (new CachedCharacterOrder())
                ->setCharacter($character)
                ->setOrderId($orderId)
                ->setTypeId($typeId)
                ->setLocationId(self::JITA_STATION_ID)
                ->setPrice(1000.0)
                ->setVolumeRemain(4)
                ->setVolumeTotal(10)
                ->setIssuedAt(new \DateTimeImmutable(self::ORDER_ISSUED_AT))
                ->setSyncedAt(new \DateTimeImmutable(self::ORDER_SYNCED_AT))
                ->setPosition($position)
                ->setUndercutSince($undercutSince === null ? null : new \DateTimeImmutable($undercutSince)),
        );
    }
}
