<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Dto\BlueprintDto;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\OwnedBlueprintRepository;
use App\Service\ESI\BlueprintsService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\BlueprintSyncService;
use App\Tests\Unit\Service\Industry\Blueprint\BuildsBlueprintLibrary;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(BlueprintSyncService::class)]
#[Group('issue-357')]
final class BlueprintSyncServiceTest extends TestCase
{
    use BuildsBlueprintLibrary;

    private const string SYNC_TYPE = 'blueprints';

    private Character $main;
    private Character $alt;
    private User $user;

    private array $events = [];

    private array $persisted = [];

    private array $mercureCalls = [];

    private array $personalReads = [];

    private array $corporationReads = [];

    private array $corporationAssets = [];

    private ?Character $corporationReader = null;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->alt = $this->pilot('Brann Holt', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->corporationReader = $this->main;
    }

    public function testReplacesTheBlueprintsOfACharacterAfterReadingThemFromTheEsiInOneFlush(): void
    {
        $this->corporationReader = null;
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->pilotWithoutCorporationScope());
        $this->personalReads['Aria Solberg'] = [
            new BlueprintDto(1_001, self::DRAKE_BLUEPRINT, 60_003_760, 'Hangar', -1, 10, 20, -1),
            new BlueprintDto(1_002, self::HAMMERHEAD_II_BLUEPRINT, 60_008_494, 'Hangar', -2, 8, 16, 40),
        ];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(
            ['read:Aria Solberg', 'delete:Aria Solberg', 'persist', 'persist', 'flush'],
            $this->events,
        );
        self::assertSame(
            [
                [1_001, self::DRAKE_BLUEPRINT, 60_003_760, 'Hangar', -1, 10, 20, -1, 'Aria Solberg', null, null],
                [1_002, self::HAMMERHEAD_II_BLUEPRINT, 60_008_494, 'Hangar', -2, 8, 16, 40, 'Aria Solberg', null, null],
            ],
            $this->persistedRows(),
        );
    }

    public function testKeepsTheCachedBlueprintsOfACharacterWhoseEsiReadFailsAndReplacesTheOtherOnes(): void
    {
        $this->corporationReader = null;
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->pilotWithoutCorporationScope(), $this->altWithoutCorporationScope());
        $this->personalReads['Aria Solberg'] = EsiApiException::fromResponse(500, 'ESI down', '/characters/1/blueprints/');
        $this->personalReads['Brann Holt'] = [new BlueprintDto(2_001, self::VEXOR_BLUEPRINT, 60_003_760, 'Hangar', -1, 6, 12, -1)];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(
            ['read:Aria Solberg', 'read:Brann Holt', 'delete:Brann Holt', 'persist', 'flush'],
            $this->events,
        );
        self::assertSame(
            [['characterName' => 'Aria Solberg', 'reason' => 'esi_unavailable', 'statusCode' => 500]],
            $this->completedData()['failedCharacters'],
        );
    }

    public function testCorporationBlueprintInACorpSagFlagTakesTheNumberOfTheDivision(): void
    {
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
        $this->personalReads['Aria Solberg'] = [];
        $this->corporationReads[self::MAIN_CORPORATION_ID] = [
            new BlueprintDto(3_001, self::HURRICANE_BLUEPRINT, 1_040_000_000_001, 'CorpSAG1', -1, 9, 18, -1),
            new BlueprintDto(3_002, self::ATRON_BLUEPRINT, 1_040_000_000_002, 'CorpSAG7', -2, 4, 8, 20),
            new BlueprintDto(3_003, self::RIFTER_BLUEPRINT, 1_040_000_000_003, 'CorpSAG3', -2, 5, 10, 15),
        ];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(
            [
                [3_001, self::MAIN_CORPORATION_ID, 1, false],
                [3_002, self::MAIN_CORPORATION_ID, 7, false],
                [3_003, self::MAIN_CORPORATION_ID, 3, false],
            ],
            array_map(
                static fn (OwnedBlueprint $blueprint): array => [$blueprint->getItemId(), $blueprint->getCorporationId(), $blueprint->getDivisionNumber(), $blueprint->getCharacter() !== null],
                $this->persisted,
            ),
        );
    }

    public function testCorporationBlueprintInAContainerTakesTheDivisionOfTheContainerOneLevelOnlyAndNullWhenItIsUnknown(): void
    {
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
        $this->personalReads['Aria Solberg'] = [];
        $this->corporationAssets = [
            9_001 => $this->corporationAsset(9_001, 'CorpSAG4', 1_040_000_000_001),
            9_003 => $this->corporationAsset(9_003, 'Unlocked', 9_004),
            9_004 => $this->corporationAsset(9_004, 'CorpSAG2', 1_040_000_000_001),
        ];
        $this->corporationReads[self::MAIN_CORPORATION_ID] = [
            new BlueprintDto(4_001, self::HURRICANE_BLUEPRINT, 9_001, 'Unlocked', -1, 9, 18, -1),
            new BlueprintDto(4_002, self::ATRON_BLUEPRINT, 9_002, 'Unlocked', -2, 4, 8, 20),
            new BlueprintDto(4_003, self::RIFTER_BLUEPRINT, 9_003, 'Unlocked', -2, 5, 10, 15),
        ];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(
            [[4_001, 4], [4_002, null], [4_003, null]],
            array_map(
                static fn (OwnedBlueprint $blueprint): array => [$blueprint->getItemId(), $blueprint->getDivisionNumber()],
                $this->persisted,
            ),
        );
    }

    public function testCharacterWithoutTheBlueprintScopesIsIgnoredAndNotListedAsAFailure(): void
    {
        $this->corporationReader = null;
        $withoutScopes = $this->pilot('Corin Vale', self::ALT_CORPORATION_ID, 'Vale Freight', []);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->pilotWithoutCorporationScope(), $withoutScopes);
        $this->personalReads['Aria Solberg'] = [new BlueprintDto(5_001, self::DRAKE_BLUEPRINT, 60_003_760, 'Hangar', -1, 10, 20, -1)];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(['read:Aria Solberg', 'delete:Aria Solberg', 'persist', 'flush'], $this->events);
        self::assertSame(
            ['characters' => 1, 'corporations' => 0, 'failedCharacters' => []],
            $this->completedData(),
        );
    }

    public function testCorporationOfTwoCharactersOfTheUserIsReadAndReplacedOnlyOnce(): void
    {
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main, $this->alt);
        $this->personalReads['Aria Solberg'] = [];
        $this->personalReads['Brann Holt'] = [];
        $this->corporationReads[self::MAIN_CORPORATION_ID] = [
            new BlueprintDto(6_001, self::HURRICANE_BLUEPRINT, 1_040_000_000_001, 'CorpSAG2', -1, 9, 18, -1),
        ];

        $this->service()->syncUserBlueprints($this->user);

        self::assertSame(
            ['read-corporation:' . self::MAIN_CORPORATION_ID => 1, 'delete-corporation:' . self::MAIN_CORPORATION_ID => 1],
            array_intersect_key(
                array_count_values($this->events),
                ['read-corporation:' . self::MAIN_CORPORATION_ID => 0, 'delete-corporation:' . self::MAIN_CORPORATION_ID => 0],
            ),
        );
        self::assertSame(2, $this->completedData()['characters']);
        self::assertSame(1, $this->completedData()['corporations']);
    }

    public function testForbiddenCorporationReadKeepsTheCachedCorporationBlueprintsAndIsListedAsAFailedCharacter(): void
    {
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
        $this->personalReads['Aria Solberg'] = [new BlueprintDto(7_001, self::DRAKE_BLUEPRINT, 60_003_760, 'Hangar', -1, 10, 20, -1)];
        $this->corporationReads[self::MAIN_CORPORATION_ID] = EsiApiException::forbidden('Character does not have required role(s)', '/corporations/98357001/blueprints/');

        $this->service()->syncUserBlueprints($this->user);

        self::assertNotContains('delete-corporation:' . self::MAIN_CORPORATION_ID, $this->events);
        self::assertSame(
            ['characters' => 1, 'corporations' => 0, 'failedCharacters' => [['characterName' => 'Aria Solberg', 'reason' => 'esi_error', 'statusCode' => 403]]],
            $this->completedData(),
        );
        self::assertNotContains('error', array_column($this->mercureCalls, 0));
    }

    public function testPublishesStartedThenProgressThenCompletedOfTheBlueprintsSyncTypeToTheUser(): void
    {
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
        $this->personalReads['Aria Solberg'] = [new BlueprintDto(8_001, self::DRAKE_BLUEPRINT, 60_003_760, 'Hangar', -1, 10, 20, -1)];
        $this->corporationReads[self::MAIN_CORPORATION_ID] = [
            new BlueprintDto(8_002, self::HURRICANE_BLUEPRINT, 1_040_000_000_001, 'CorpSAG2', -1, 9, 18, -1),
        ];

        $this->service()->syncUserBlueprints($this->user);

        $statuses = array_column($this->mercureCalls, 0);
        self::assertSame(['started'], array_slice($statuses, 0, 1));
        self::assertSame(['completed'], array_slice($statuses, -1));
        self::assertSame(['in_progress'], array_values(array_unique(array_slice($statuses, 1, -1))));
        self::assertSame(
            ['/user/' . self::MAIN_USER_ID . '/sync/' . self::SYNC_TYPE],
            array_values(array_unique(array_column($this->mercureCalls, 1))),
        );
        self::assertSame([self::SYNC_TYPE], array_values(array_unique(array_column($this->mercureCalls, 2))));
        self::assertSame(
            ['characters' => 1, 'corporations' => 1, 'failedCharacters' => []],
            $this->completedData(),
        );
    }

    private function pilotWithoutCorporationScope(): Character
    {
        return $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE]);
    }

    private function altWithoutCorporationScope(): Character
    {
        return $this->pilot('Brann Holt', self::ALT_CORPORATION_ID, 'Holt Haulage', [self::PERSONAL_SCOPE]);
    }

    private function corporationAsset(int $itemId, string $locationFlag, int $locationId): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setLocationFlag($locationFlag)
            ->setLocationId($locationId)
            ->setCorporationId(self::MAIN_CORPORATION_ID);
    }

    private function service(): BlueprintSyncService
    {
        $blueprintsService = $this->createStub(BlueprintsService::class);
        $blueprintsService->method('getCharacterBlueprints')->willReturnCallback(
            function (Character $character): array {
                $this->events[] = 'read:' . $character->getName();

                return $this->outcomeOf($this->personalReads[$character->getName()]);
            },
        );
        $blueprintsService->method('getCorporationBlueprints')->willReturnCallback(
            function (Character $character): array {
                $this->events[] = 'read-corporation:' . $character->getCorporationId();

                return $this->outcomeOf($this->corporationReads[$character->getCorporationId()]);
            },
        );

        $ownedBlueprintRepository = $this->createStub(OwnedBlueprintRepository::class);
        $ownedBlueprintRepository->method('deleteByCharacter')->willReturnCallback(
            function (Character $character): int {
                $this->events[] = 'delete:' . $character->getName();

                return 0;
            },
        );
        $ownedBlueprintRepository->method('deleteByCorporationId')->willReturnCallback(
            function (int $corporationId): int {
                $this->events[] = 'delete-corporation:' . $corporationId;

                return 0;
            },
        );

        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?CachedAsset => $this->corporationAssets[$criteria['itemId']] ?? null,
        );

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findWithCorpBlueprintsAccess')->willReturnCallback(
            fn (int $corporationId): ?Character => $this->corporationReader,
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            function (object $entity): void {
                $this->events[] = 'persist';
                if ($entity instanceof OwnedBlueprint) {
                    $this->persisted[] = $entity;
                }
            },
        );
        $entityManager->method('flush')->willReturnCallback(
            function (): void {
                $this->events[] = 'flush';
            },
        );

        return new BlueprintSyncService(
            $blueprintsService,
            $ownedBlueprintRepository,
            $cachedAssetRepository,
            $characterRepository,
            $this->visibilityOf([]),
            $entityManager,
            new NullLogger(),
            $this->mercurePublisher(),
        );
    }

    private function outcomeOf(array|\Throwable $outcome): array
    {
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    private function mercurePublisher(): MercurePublisherService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            function (Update $update): string {
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                $this->mercureCalls[] = [$payload['status'], $update->getTopics()[0], $payload['syncType'], $payload['data']];

                return 'urn:uuid:published';
            },
        );

        return new MercurePublisherService($hub, new NullLogger());
    }

    private function completedData(): array
    {
        $completed = array_values(array_filter($this->mercureCalls, static fn (array $call): bool => $call[0] === 'completed'));

        return $completed[0][3] ?? [];
    }

    private function persistedRows(): array
    {
        return array_map(
            static fn (OwnedBlueprint $blueprint): array => [
                $blueprint->getItemId(),
                $blueprint->getTypeId(),
                $blueprint->getLocationId(),
                $blueprint->getLocationFlag(),
                $blueprint->getQuantity(),
                $blueprint->getMaterialEfficiency(),
                $blueprint->getTimeEfficiency(),
                $blueprint->getRuns(),
                $blueprint->getCharacter()?->getName(),
                $blueprint->getCorporationId(),
                $blueprint->getDivisionNumber(),
            ],
            $this->persisted,
        );
    }
}
