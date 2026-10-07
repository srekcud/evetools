<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\TriggerAnsiblexSync;
use App\MessageHandler\TriggerAnsiblexSyncHandler;
use App\Repository\AnsiblexJumpGateRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\AnsiblexSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Issue #34 : à chaque run planifié de la sync Ansiblex, les portes qu'aucune sync n'a vues
 * depuis 7 jours (`lastSeenAt`) sont désactivées ; les portes vues récemment restent actives
 * (filtre de `deactivateStaleGates()`, couvert par AnsiblexJumpGateRepositoryTest).
 */
#[CoversClass(TriggerAnsiblexSyncHandler::class)]
final class TriggerAnsiblexSyncHandlerTest extends TestCase
{
    private const STALE_GATE_THRESHOLD_SECONDS = 7 * 86400;

    /** Marge pour l'horloge système (le handler n'a pas d'horloge injectée). */
    private const CLOCK_TOLERANCE_SECONDS = 5;

    public function testRunDeactivatesGatesNotSeenForSevenDays(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findActiveWithCharacters')->willReturn([]);

        $receivedThreshold = null;
        $gateRepository = $this->createMock(AnsiblexJumpGateRepository::class);
        $gateRepository->expects($this->once())
            ->method('deactivateStaleGates')
            ->willReturnCallback(function (\DateTimeImmutable $threshold) use (&$receivedThreshold): int {
                $receivedThreshold = $threshold;

                return 2;
            });

        $handler = new TriggerAnsiblexSyncHandler(
            $userRepository,
            $this->createStub(AnsiblexSyncService::class),
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
            $this->createStub(SyncTracker::class),
            $gateRepository,
        );

        $runAt = time();
        $handler(new TriggerAnsiblexSync());

        self::assertInstanceOf(\DateTimeImmutable::class, $receivedThreshold, 'deactivateStaleGates() non appelé par le run.');
        self::assertEqualsWithDelta(
            $runAt - self::STALE_GATE_THRESHOLD_SECONDS,
            $receivedThreshold->getTimestamp(),
            self::CLOCK_TOLERANCE_SECONDS,
        );
    }
}
