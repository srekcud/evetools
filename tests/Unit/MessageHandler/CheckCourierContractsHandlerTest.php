<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\User;
use App\Message\CheckCourierContracts;
use App\MessageHandler\CheckCourierContractsHandler;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\Courier\CourierContractReader;
use App\Service\Industry\Project\Courier\CourierFailureNotifier;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckCourierContractsHandler::class)]
#[Group('issue-422')]
final class CheckCourierContractsHandlerTest extends TestCase
{
    private const int AMBER_PILOT_ID = 2_112_422_201;
    private const int FAILED_CONTRACT_ID = 422_201;

    public function testTracksTheCourierContractsSyncAndReportsTheNumberOfAlerts(): void
    {
        $calls = [];
        $handler = new CheckCourierContractsHandler($this->notifierWithOneFailedLink(), $this->trackerRecordingInto($calls));

        $handler(new CheckCourierContracts());

        self::assertSame([['start', 'courier-contracts'], ['complete', 'courier-contracts', '1 alert(s) sent']], $calls);
    }

    public function testFailureIsTrackedThenRethrown(): void
    {
        $repository = $this->createStub(IndustryProjectCourierContractRepository::class);
        $repository->method('findPendingFailureCheck')->willThrowException(new \RuntimeException('database down'));
        $notifier = new CourierFailureNotifier(
            $repository,
            new CourierContractReader($this->createStub(EsiClient::class), $this->createStub(TokenManager::class)),
            $this->createStub(NotificationDispatcher::class),
            $this->createStub(EntityManagerInterface::class),
        );
        $calls = [];

        try {
            (new CheckCourierContractsHandler($notifier, $this->trackerRecordingInto($calls)))(new CheckCourierContracts());
            self::fail('The handler must rethrow the failure');
        } catch (\RuntimeException $failure) {
            self::assertSame('database down', $failure->getMessage());
        }

        self::assertSame([['start', 'courier-contracts'], ['fail', 'courier-contracts', 'database down']], $calls);
    }

    private function notifierWithOneFailedLink(): CourierFailureNotifier
    {
        $owner = new User();
        $owner->addCharacter((new Character())->setEveCharacterId(self::AMBER_PILOT_ID)->setName('Pilot')->setEveToken((new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-contracts.read_character_contracts.v1'])));
        $link = (new IndustryProjectCourierContract())
            ->setProject((new IndustryProject())->setOwner($owner)->setName('Ishtar batch'))
            ->setContractId(self::FAILED_CONTRACT_ID)
            ->setCharacterId(self::AMBER_PILOT_ID);

        $repository = $this->createStub(IndustryProjectCourierContractRepository::class);
        $repository->method('findPendingFailureCheck')->willReturn([$link]);
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn([[
            'contract_id' => self::FAILED_CONTRACT_ID,
            'type' => 'courier',
            'status' => 'failed',
            'date_issued' => (new \DateTimeImmutable('+1 hour'))->format('c'),
        ]]);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        return new CourierFailureNotifier(
            $repository,
            new CourierContractReader($esiClient, $tokenManager),
            $this->createStub(NotificationDispatcher::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /**
     * @param list<array<int, string|null>> $calls
     */
    private function trackerRecordingInto(array &$calls): SyncTracker
    {
        $tracker = $this->createStub(SyncTracker::class);
        $tracker->method('start')->willReturnCallback(static function (string $type) use (&$calls): void {
            $calls[] = ['start', $type];
        });
        $tracker->method('complete')->willReturnCallback(static function (string $type, ?string $message = null) use (&$calls): void {
            $calls[] = ['complete', $type, $message];
        });
        $tracker->method('fail')->willReturnCallback(static function (string $type, string $message) use (&$calls): void {
            $calls[] = ['fail', $type, $message];
        });

        return $tracker;
    }
}
