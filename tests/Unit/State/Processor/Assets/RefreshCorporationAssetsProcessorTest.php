<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncCorporationAssets;
use App\Service\Sync\AssetsSyncService;
use App\State\Processor\Assets\RefreshCorporationAssetsProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RefreshCorporationAssetsProcessor::class)]
#[Group('issue-668')]
final class RefreshCorporationAssetsProcessorTest extends TestCase
{
    private const string REQUESTER_ID = '0199a1f0-0000-7000-8000-000000668001';
    private const string DIRECTOR_CHARACTER_ID = '0199a1f0-0000-7000-8000-000000668002';
    private const int CORPORATION_ID = 98_000_001;

    public function testAsyncRefreshDispatchesTheSyncWithTheRequestingUserAndTheSyncCharacter(): void
    {
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $this->processor($messageBus)->process(null, new Post());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SyncCorporationAssets::class, $dispatched[0]);
        self::assertSame(
            [
                'corporationId' => self::CORPORATION_ID,
                'triggerCharacterId' => self::DIRECTOR_CHARACTER_ID,
                'requestedByUserId' => self::REQUESTER_ID,
            ],
            get_object_vars($dispatched[0]),
        );
    }

    #[Group('issue-729')]
    public function testRefreshWithoutAConnectedDirectorDispatchesNothingAndSaysSo(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = $this->processor($messageBus, false)->process(null, new Post());

        self::assertSame(['error', false], [$result->status, $result->hasAccess]);
        self::assertStringContainsString('No Director connected', (string) $result->error);
        self::assertStringNotContainsString('Access forbidden', (string) $result->error);
    }

    private function processor(MessageBusInterface $messageBus, bool $withSyncCharacter = true): RefreshCorporationAssetsProcessor
    {
        $requester = new User();
        (new \ReflectionProperty($requester, 'id'))->setValue($requester, Uuid::fromString(self::REQUESTER_ID));
        $mainCharacter = (new Character())->setCorporationId(self::CORPORATION_ID);
        $requester->setMainCharacter($mainCharacter);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($requester);

        $directorCharacter = new Character();
        (new \ReflectionProperty($directorCharacter, 'id'))->setValue($directorCharacter, Uuid::fromString(self::DIRECTOR_CHARACTER_ID));
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('getCorpAssetsCharacter')->willReturn($withSyncCharacter ? $directorCharacter : null);

        return new RefreshCorporationAssetsProcessor(
            $security,
            $assetsSyncService,
            $messageBus,
            new RequestStack(),
            new NullLogger(),
        );
    }
}
