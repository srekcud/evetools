<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncIndustryJobs;
use App\MessageHandler\SyncIndustryJobsHandler;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryStepJobMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\IndustryJobSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncIndustryJobsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
class SyncIndustryJobsHandlerTest extends TestCase
{
    private const int CORPORATION_ID = 98000001;

    private EsiClient&MockObject $esiClient;
    private SyncIndustryJobsHandler $handler;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->esiClient->method('get')->willReturn([]);

        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findActiveJobsByCharacter')->willReturn([]);

        // Real sync service: only its boundaries (ESI, database) are doubled, so the test
        // does not depend on how the handler refreshes the corporation tracking.
        $industryJobSyncService = new IndustryJobSyncService(
            $this->esiClient,
            $jobRepository,
            $this->createStub(InvTypeRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->createStub(IndustryStepJobMatchRepository::class),
            $this->createStub(NotificationDispatcher::class),
        );

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')
            ->willReturn([$this->createCharacterOfCorporation(12345, self::CORPORATION_ID)]);

        $this->handler = new SyncIndustryJobsHandler(
            $characterRepository,
            $industryJobSyncService,
            new NullLogger(),
            $this->createStub(SyncTracker::class),
        );
    }

    // ===========================================
    // Issue #29: each scheduled sync refreshes corporation jobs
    // ===========================================

    public function testEachHandledSyncMessageFetchesCorporationJobsAgain(): void
    {
        $this->esiClient->expects($this->exactly(2))
            ->method('getPaginated')
            ->with('/corporations/' . self::CORPORATION_ID . '/industry/jobs/?include_completed=true')
            ->willReturn([]);

        // Same handler and service instances, as in a long-running worker
        ($this->handler)(new SyncIndustryJobs());
        ($this->handler)(new SyncIndustryJobs());
    }

    private function createCharacterOfCorporation(int $eveCharacterId, int $corporationId): Character
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn("Char{$eveCharacterId}");
        $character->method('getUser')->willReturn($user);
        $character->method('getCorporationId')->willReturn($corporationId);

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $character;
    }
}
