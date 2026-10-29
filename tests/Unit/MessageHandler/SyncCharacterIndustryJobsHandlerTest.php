<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Dto\CharacterInfoDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncCharacterIndustryJobs;
use App\MessageHandler\SyncCharacterIndustryJobsHandler;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryStepJobMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\CharacterService;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\IndustryJobSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncCharacterIndustryJobsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncCharacterIndustryJobsHandlerTest extends TestCase
{
    private const string CHARACTER_ID = '0199a1f0-0000-7000-8000-0000000000d4';
    private const string OTHER_CHARACTER_ID = '0199a1f0-0000-7000-8000-0000000000c7';
    private const string DELETED_CHARACTER_ID = '0199a1f0-0000-7000-8000-0000000000e5';
    private const int EVE_CHARACTER_ID = 2119000777;
    private const int CORPORATION_ID = 98000431;

    public function testSyncsTheCharacterNamedByTheMessage(): void
    {
        $character = $this->character(self::CHARACTER_ID);
        $otherCharacter = $this->character(self::OTHER_CHARACTER_ID);

        $serviceCalls = [];
        $industryJobSyncService = $this->createStub(IndustryJobSyncService::class);
        $industryJobSyncService->method('syncCharacterJobs')->willReturnCallback(
            static function (Character $syncedCharacter) use (&$serviceCalls, $character, $otherCharacter): void {
                $serviceCalls[] = match ($syncedCharacter) {
                    $character => 'syncCharacterJobs:' . self::CHARACTER_ID,
                    $otherCharacter => 'syncCharacterJobs:' . self::OTHER_CHARACTER_ID,
                    default => 'syncCharacterJobs:unknown',
                };
            },
        );

        $handler = new SyncCharacterIndustryJobsHandler(
            $this->repositoryHolding([self::CHARACTER_ID => $character, self::OTHER_CHARACTER_ID => $otherCharacter]),
            $industryJobSyncService,
            new NullLogger(),
        );

        $handler(new SyncCharacterIndustryJobs(self::CHARACTER_ID));

        self::assertSame(['syncCharacterJobs:' . self::CHARACTER_ID], $serviceCalls);
    }

    public function testCharacterDeletedSinceDispatchIsSkippedWithAWarning(): void
    {
        $industryJobSyncService = $this->createMock(IndustryJobSyncService::class);
        $industryJobSyncService->expects($this->never())->method('syncCharacterJobs');

        $logger = new BufferingLogger();
        $handler = new SyncCharacterIndustryJobsHandler($this->repositoryHolding([]), $industryJobSyncService, $logger);

        $handler(new SyncCharacterIndustryJobs(self::DELETED_CHARACTER_ID));

        $levels = array_map(static fn (array $log): string => $log[0], $logger->cleanLogs());
        self::assertSame([LogLevel::WARNING], $levels);
    }

    public function testCharacterSyncFailurePropagatesToTheBus(): void
    {
        $character = $this->character(self::CHARACTER_ID);

        $industryJobSyncService = $this->createStub(IndustryJobSyncService::class);
        $industryJobSyncService->method('syncCharacterJobs')
            ->willThrowException(new \RuntimeException('ESI error budget exhausted'));

        $handler = new SyncCharacterIndustryJobsHandler(
            $this->repositoryHolding([self::CHARACTER_ID => $character]),
            $industryJobSyncService,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ESI error budget exhausted');

        $handler(new SyncCharacterIndustryJobs(self::CHARACTER_ID));
    }

    #[Group('issue-213')]
    public function testTwoHandledMessagesForCharactersOfSameCorporationReadCorporationJobsOnce(): void
    {
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->method('get')->willReturn([]);
        $esiClient->expects($this->once())
            ->method('getPaginatedWithExpiry')
            ->with('/corporations/' . self::CORPORATION_ID . '/industry/jobs/?include_completed=true')
            ->willReturn(new PaginatedEsiResponse([], new \DateTimeImmutable('+300 seconds')));

        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findActiveJobsByCharacter')->willReturn([]);

        $industryJobSyncService = new IndustryJobSyncService(
            $esiClient,
            $jobRepository,
            $this->createStub(InvTypeRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->createStub(IndustryStepJobMatchRepository::class),
            $this->createStub(NotificationDispatcher::class),
            new ArrayAdapter(),
            $this->characterServiceReadingCorporation(self::CORPORATION_ID),
        );

        [$character, $alt] = $this->charactersOfCorporation(self::CHARACTER_ID, self::OTHER_CHARACTER_ID);
        $handler = new SyncCharacterIndustryJobsHandler(
            $this->repositoryHolding([self::CHARACTER_ID => $character, self::OTHER_CHARACTER_ID => $alt]),
            $industryJobSyncService,
            new NullLogger(),
        );

        $handler(new SyncCharacterIndustryJobs(self::CHARACTER_ID));
        $handler(new SyncCharacterIndustryJobs(self::OTHER_CHARACTER_ID));
    }

    /** @param array<string, Character> $charactersById */
    private function repositoryHolding(array $charactersById): CharacterRepository
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?Character => $charactersById[(string) $id] ?? null,
        );

        return $characterRepository;
    }

    private function characterServiceReadingCorporation(int $corporationId): CharacterService
    {
        $characterService = $this->createStub(CharacterService::class);
        $characterService->method('getCharacterInfo')->willReturnCallback(
            static fn (int $eveCharacterId): CharacterInfoDto => new CharacterInfoDto(
                $eveCharacterId,
                'Industrialist ' . $eveCharacterId,
                $corporationId,
                'Handler Corp',
            ),
        );

        return $characterService;
    }

    private function character(string $characterId): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString($characterId));

        return $character;
    }

    private function charactersOfCorporation(string ...$characterIds): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString('0199a1f0-0000-7000-8000-0000000000f6'));

        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);

        $characters = [];
        foreach ($characterIds as $index => $characterId) {
            $eveCharacterId = self::EVE_CHARACTER_ID + $index;
            $character = $this->createStub(Character::class);
            $character->method('getId')->willReturn(Uuid::fromString($characterId));
            $character->method('getEveCharacterId')->willReturn($eveCharacterId);
            $character->method('getEveToken')->willReturn($token);
            $character->method('getName')->willReturn('Industrialist ' . $eveCharacterId);
            $character->method('getUser')->willReturn($user);
            $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);
            $characters[] = $character;
        }

        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $characters;
    }
}
