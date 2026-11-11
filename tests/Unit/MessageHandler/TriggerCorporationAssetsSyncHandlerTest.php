<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Message\SyncCorporationAssets;
use App\Message\TriggerCorporationAssetsSync;
use App\MessageHandler\TriggerCorporationAssetsSyncHandler;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Sync\AssetsSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TriggerCorporationAssetsSyncHandler::class)]
final class TriggerCorporationAssetsSyncHandlerTest extends TestCase
{
    private const FIRST_CORPORATION_ID = 98_700_001;
    private const SECOND_CORPORATION_ID = 98_700_002;
    private const THIRD_CORPORATION_ID = 98_700_003;

    /** @var list<object> */
    private array $dispatchedMessages = [];

    public function testEachCorporationWithAssetsAccessGetsOneSyncDispatchedForItsCharacterAndItsOwner(): void
    {
        $firstCharacter = $this->characterOwnedBy($this->uuidOf('a1'), $this->uuidOf('b1'));
        $secondCharacter = $this->characterOwnedBy($this->uuidOf('a2'), $this->uuidOf('b2'));

        $this->handlerFor(
            [self::FIRST_CORPORATION_ID => $firstCharacter, self::SECOND_CORPORATION_ID => $secondCharacter],
        )(new TriggerCorporationAssetsSync());

        self::assertEquals(
            [
                new SyncCorporationAssets(self::FIRST_CORPORATION_ID, $this->uuidOf('a1'), $this->uuidOf('b1')),
                new SyncCorporationAssets(self::SECOND_CORPORATION_ID, $this->uuidOf('a2'), $this->uuidOf('b2')),
            ],
            $this->dispatchedMessages,
        );
    }

    public function testACorporationWithoutAnyCharacterWithTheScopeIsSkipped(): void
    {
        $character = $this->characterOwnedBy($this->uuidOf('a1'), $this->uuidOf('b1'));

        $this->handlerFor(
            [self::FIRST_CORPORATION_ID => $character, self::SECOND_CORPORATION_ID => null, self::THIRD_CORPORATION_ID => null],
        )(new TriggerCorporationAssetsSync());

        self::assertEquals(
            [new SyncCorporationAssets(self::FIRST_CORPORATION_ID, $this->uuidOf('a1'), $this->uuidOf('b1'))],
            $this->dispatchedMessages,
        );
    }

    public function testNothingIsDispatchedWhenNoCorporationSharesItsAssets(): void
    {
        $this->handlerFor([])(new TriggerCorporationAssetsSync());

        self::assertSame([], $this->dispatchedMessages);
    }

    public function testACharacterWithoutOwnerIsSkipped(): void
    {
        $orphan = $this->createStub(Character::class);
        $orphan->method('getId')->willReturn(Uuid::fromString($this->uuidOf('a1')));
        $orphan->method('getUser')->willReturn(null);

        $this->handlerFor([self::FIRST_CORPORATION_ID => $orphan])(new TriggerCorporationAssetsSync());

        self::assertSame([], $this->dispatchedMessages);
    }

    /**
     * @param array<int, Character|null> $characterByCorporationId
     */
    private function handlerFor(array $characterByCorporationId): TriggerCorporationAssetsSyncHandler
    {
        $visibilities = array_map(
            static function (int $corporationId): CorpAssetVisibility {
                return (new CorpAssetVisibility())->setCorporationId($corporationId);
            },
            array_keys($characterByCorporationId),
        );

        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findAll')->willReturn($visibilities);

        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('getCorpAssetsCharacter')->willReturnCallback(
            static fn (int $corporationId): ?Character => $characterByCorporationId[$corporationId] ?? null,
        );

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        return new TriggerCorporationAssetsSyncHandler($visibilityRepository, $assetsSyncService, $messageBus);
    }

    private function characterOwnedBy(string $characterId, string $ownerId): Character
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(Uuid::fromString($ownerId));

        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString($characterId));
        $character->method('getUser')->willReturn($owner);

        return $character;
    }

    private function uuidOf(string $suffix): string
    {
        return sprintf('00000000-0000-4000-8000-0000000000%s', $suffix);
    }
}
