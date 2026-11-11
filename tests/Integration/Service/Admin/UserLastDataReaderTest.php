<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Entity\CachedCharacterOrder;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\UserLedgerSettings;
use App\Entity\UserPveSettings;
use App\Dto\OrderPosition;
use App\Enum\OrderPositionState;
use App\Service\Admin\UserLastDataReader;
use App\Service\Admin\UserTriggerableSyncs;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-647')]
final class UserLastDataReaderTest extends IntegrationTestCase
{
    private UserLastDataReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = self::getContainer()->get(UserLastDataReader::class);
    }

    public function testAUserWithoutAnyDataHasNullForEveryType(): void
    {
        $user = $this->createUser();
        $this->createCharacter($user);
        $this->flushAndClear();

        $lastData = $this->reader->lastDataByType($this->em->find($user::class, $user->getId()));

        self::assertSame(UserTriggerableSyncs::TYPES, array_keys($lastData));
        self::assertSame([], array_filter($lastData, static fn (?\DateTimeImmutable $date): bool => $date !== null));
    }

    public function testTheLatestWriteAcrossTheCharactersOfTheUserWins(): void
    {
        $user = $this->createUser();
        $first = $this->createCharacter($user, 'First');
        $second = $this->createCharacter($user, 'Second');
        $this->persistOrder($first, 1, '2026-11-05 08:00:00');
        $this->persistOrder($second, 2, '2026-11-09 08:00:00');
        $this->persistBlueprint($first, 10, '2026-11-07 02:00:00');
        $this->flushAndClear();

        $lastData = $this->reader->lastDataByType($this->em->find($user::class, $user->getId()));

        self::assertSame('2026-11-09 08:00:00', $lastData['character-orders']?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-07 02:00:00', $lastData['blueprints']?->format('Y-m-d H:i:s'));
        self::assertNull($lastData['industry']);
    }

    public function testTheDataOfAnotherUserIsIgnored(): void
    {
        $user = $this->createUser();
        $this->createCharacter($user, 'Mine');
        $stranger = $this->createCharacter(null, 'Stranger');
        $this->persistOrder($stranger, 3, '2026-11-09 08:00:00');
        $this->flushAndClear();

        $lastData = $this->reader->lastDataByType($this->em->find($user::class, $user->getId()));

        self::assertNull($lastData['character-orders']);
    }

    public function testThePveAndMiningTimestampsComeFromTheUserSettings(): void
    {
        $user = $this->createUser();
        $this->createCharacter($user);
        $this->em->persist((new UserPveSettings())->setUser($user)->setLastSyncAt(new \DateTimeImmutable('2026-11-06 10:00:00')));
        $this->em->persist((new UserLedgerSettings())->setUser($user)->setLastMiningSyncAt(new \DateTimeImmutable('2026-11-08 11:00:00')));
        $this->flushAndClear();

        $lastData = $this->reader->lastDataByType($this->em->find($user::class, $user->getId()));

        self::assertSame('2026-11-06 10:00:00', $lastData['pve']?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-08 11:00:00', $lastData['mining']?->format('Y-m-d H:i:s'));
    }

    private function persistOrder(Character $character, int $orderId, string $syncedAt): void
    {
        $this->em->persist(
            (new CachedCharacterOrder())
                ->setCharacter($character)
                ->setOrderId($orderId)
                ->setTypeId(587)
                ->setLocationId(60_003_760)
                ->setPrice(1000.0)
                ->setVolumeRemain(4)
                ->setVolumeTotal(10)
                ->setIssuedAt(new \DateTimeImmutable('2026-11-01 08:00:00'))
                ->setSyncedAt(new \DateTimeImmutable($syncedAt))
                ->setPosition(new OrderPosition(OrderPositionState::Undercut, 2, 900.0, null, 800.0)),
        );
    }

    private function persistBlueprint(Character $character, int $itemId, string $syncedAt): void
    {
        $this->em->persist(
            (new OwnedBlueprint())
                ->setItemId($itemId)
                ->setTypeId(587)
                ->setLocationId(60_003_760)
                ->setLocationFlag('Hangar')
                ->setQuantity(-1)
                ->setRuns(-1)
                ->setMaterialEfficiency(10)
                ->setTimeEfficiency(20)
                ->setCharacter($character)
                ->setSyncedAt(new \DateTimeImmutable($syncedAt)),
        );
    }
}
