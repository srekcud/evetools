<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Exception\EsiApiException;
use App\Message\SyncStructureMarket;
use App\MessageHandler\SyncStructureMarketHandler;
use App\Repository\CharacterRepository;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #28: the result of the structure market sync was ignored, a failed sync was acknowledged as a success.
 */
#[CoversClass(SyncStructureMarketHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncStructureMarketHandlerTest extends TestCase
{
    use StructureMarketSyncFixtures;

    private const int STRUCTURE_ID = 1_046_664_001_931;
    private const string STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon';

    private EsiClient&MockObject $esiClient;
    private CharacterRepository&MockObject $characterRepository;
    private SyncStructureMarketHandler $handler;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->characterRepository = $this->createMock(CharacterRepository::class);

        $this->handler = new SyncStructureMarketHandler(
            $this->characterRepository,
            $this->createStructureMarketService($this->esiClient),
            new NullLogger(),
        );
    }

    public function testSyncsTheStructureWithTheTokenOfTheGivenCharacter(): void
    {
        $requester = $this->givenCharacter($this->createPilot('Requester'));

        $this->esiClient->expects($this->once())
            ->method('getPaginated')
            ->with('/markets/structures/' . self::STRUCTURE_ID . '/', $requester->getEveToken())
            ->willReturn([]);

        ($this->handler)($this->messageFor($requester));
    }

    public function testPublishesMercureEventsToTheUserOfTheGivenCharacter(): void
    {
        $requester = $this->givenCharacter($this->createPilot('Requester'));
        $this->esiClient->method('getPaginated')->willReturn([]);

        ($this->handler)($this->messageFor($requester));

        // started, progress 50, progress 80, completed
        self::assertSame(array_fill(0, 4, $this->marketStructureTopic($requester)), $this->publishedTopics);
    }

    public function testFailsTheMessageWhenTheStructureSyncFails(): void
    {
        $requester = $this->givenCharacter($this->createPilot('Requester'));
        $this->esiClient->method('getPaginated')
            ->willThrowException(EsiApiException::fromResponse(403, 'ESI request failed', '/markets/structures/' . self::STRUCTURE_ID . '/'));

        // An ignored `success: false` acknowledged the message as if the market had been refreshed.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) self::STRUCTURE_ID);

        ($this->handler)($this->messageFor($requester));
    }

    public function testDoesNotCallEsiWhenTheTokenLacksTheStructureMarketScope(): void
    {
        $pilotWithoutScope = $this->givenCharacter($this->createPilot('No Scope', ['esi-assets.read_assets.v1']));

        $this->esiClient->expects($this->never())->method('getPaginated');

        try {
            ($this->handler)($this->messageFor($pilotWithoutScope));
        } catch (\RuntimeException) {
            // Reporting the skip as a failure is acceptable; calling ESI is not.
        }
    }

    private function givenCharacter(Character $character): Character
    {
        $this->characterRepository->method('find')->willReturn($character);

        return $character;
    }

    private function messageFor(Character $character): SyncStructureMarket
    {
        return new SyncStructureMarket(self::STRUCTURE_ID, self::STRUCTURE_NAME, $character->getId()->toRfc4122());
    }
}
