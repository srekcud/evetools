<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\Courier\CourierContractReader;
use App\Service\Industry\Project\Courier\CourierFailureNotifier;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierFailureNotifier::class)]
#[Group('issue-422')]
final class CourierFailureNotifierTest extends TestCase
{
    private const int AMBER_PILOT_ID = 2_112_422_001;
    private const int COBALT_PILOT_ID = 2_112_422_002;

    /** @var list<array<string, mixed>> */
    private array $esiContracts = [];
    private int $esiReads = 0;
    /** @var list<IndustryProjectCourierContract> */
    private array $pendingLinks = [];
    /** @var list<array{User, string, string, string, string, array<string, mixed>|null, string|null}> */
    private array $dispatched = [];
    private int $flushes = 0;
    private CourierFailureNotifier $notifier;

    protected function setUp(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(function (): array {
            ++$this->esiReads;

            return $this->esiContracts;
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        $repository = $this->createStub(IndustryProjectCourierContractRepository::class);
        $repository->method('findPendingFailureCheck')->willReturnCallback(fn (): array => $this->pendingLinks);

        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
            $this->dispatched[] = [$user, $category, $level, $title, $message, $data, $route];
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $this->notifier = new CourierFailureNotifier($repository, new CourierContractReader($esiClient, $tokenManager), $dispatcher, $entityManager);
    }

    public function testFailedContractAlertsTheOwnerOnceAndMarksTheLinkAsNotified(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $project = $this->projectOf($owner, 'Ishtar batch');
        $link = $this->linkOf($project, 422_001);
        $this->pendingLinks = [$link];
        $this->esiContracts = [$this->courierContract(422_001, 'failed')];

        $alerts = $this->notifier->notifyFailedContracts();

        self::assertSame(1, $alerts);
        self::assertCount(1, $this->dispatched);
        [$user, $category, $level, $title, $message, $data, $route] = $this->dispatched[0];
        self::assertSame($owner, $user);
        self::assertSame(Notification::CATEGORY_INDUSTRY, $category);
        self::assertSame(Notification::LEVEL_WARNING, $level);
        self::assertSame('Courier contract failed: Ishtar batch', $title);
        self::assertStringContainsString('Ishtar batch', $message);
        self::assertStringContainsString('422001', str_replace([' ', "\u{202f}", ','], '', $message));
        self::assertSame(['projectId' => $project->getId()->toRfc4122(), 'contractId' => 422_001], $data);
        self::assertSame('/industry/projects/'.$project->getId()->toRfc4122(), $route);
        self::assertNotNull($link->getFailureNotifiedAt());
        self::assertGreaterThanOrEqual(1, $this->flushes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failureStatuses(): iterable
    {
        yield 'rejected' => ['rejected'];
        yield 'cancelled' => ['cancelled'];
        yield 'deleted' => ['deleted'];
        yield 'reversed' => ['reversed'];
    }

    #[DataProvider('failureStatuses')]
    public function testEveryFailureStatusAlertsTheOwner(string $esiStatus): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $link = $this->linkOf($this->projectOf($owner, 'Hecate batch'), 422_002);
        $this->pendingLinks = [$link];
        $this->esiContracts = [$this->courierContract(422_002, $esiStatus)];

        self::assertSame(1, $this->notifier->notifyFailedContracts());
        self::assertNotNull($link->getFailureNotifiedAt());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonFailureStatuses(): iterable
    {
        yield 'outstanding' => ['outstanding'];
        yield 'in_progress' => ['in_progress'];
        yield 'finished' => ['finished'];
        yield 'unrecognized' => ['awaiting_review'];
    }

    #[DataProvider('nonFailureStatuses')]
    public function testContractThatHasNotFailedNeverAlertsAndStaysWatched(string $esiStatus): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $link = $this->linkOf($this->projectOf($owner, 'Ishtar batch'), 422_003);
        $this->pendingLinks = [$link];
        $this->esiContracts = [$this->courierContract(422_003, $esiStatus)];

        self::assertSame(0, $this->notifier->notifyFailedContracts());
        self::assertSame([], $this->dispatched);
        self::assertNull($link->getFailureNotifiedAt());
    }

    public function testContractMissingFromTheEsiAnswerAndCharacterWithoutTokenNeverAlert(): void
    {
        $ownerWithoutToken = $this->ownerWithPilots([self::COBALT_PILOT_ID => false]);
        $ownerWithToken = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $unreadableLink = $this->linkOf($this->projectOf($ownerWithoutToken, 'Hecate batch'), 422_004, self::COBALT_PILOT_ID);
        $missingLink = $this->linkOf($this->projectOf($ownerWithToken, 'Ishtar batch'), 422_005);
        $this->pendingLinks = [$unreadableLink, $missingLink];
        $this->esiContracts = [$this->courierContract(422_999, 'failed')];

        self::assertSame(0, $this->notifier->notifyFailedContracts());
        self::assertSame([], $this->dispatched);
        self::assertNull($unreadableLink->getFailureNotifiedAt());
        self::assertNull($missingLink->getFailureNotifiedAt());
    }

    public function testAnAlreadyNotifiedLinkIsNotAlertedAgainOnTheNextRun(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $this->pendingLinks = [$this->linkOf($this->projectOf($owner, 'Ishtar batch'), 422_006)];
        $this->esiContracts = [$this->courierContract(422_006, 'failed')];

        $firstRun = $this->notifier->notifyFailedContracts();
        $secondRun = $this->notifier->notifyFailedContracts();

        self::assertSame([1, 0], [$firstRun, $secondRun]);
        self::assertCount(1, $this->dispatched);
    }

    public function testTwoProjectsOfTheSameOwnerAreAlertedFromASingleEsiRead(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $this->pendingLinks = [
            $this->linkOf($this->projectOf($owner, 'Ishtar batch'), 422_007),
            $this->linkOf($this->projectOf($owner, 'Hecate batch'), 422_008),
        ];
        $this->esiContracts = [$this->courierContract(422_007, 'failed'), $this->courierContract(422_008, 'cancelled')];

        $alerts = $this->notifier->notifyFailedContracts();

        self::assertSame(2, $alerts);
        self::assertSame(1, $this->esiReads);
        self::assertSame(
            ['Courier contract failed: Ishtar batch', 'Courier contract failed: Hecate batch'],
            array_map(static fn (array $call): string => $call[3], $this->dispatched),
        );
    }

    private function projectOf(User $owner, string $name): IndustryProject
    {
        return (new IndustryProject())->setOwner($owner)->setName($name);
    }

    private function linkOf(IndustryProject $project, int $contractId, int $characterId = self::AMBER_PILOT_ID): IndustryProjectCourierContract
    {
        return (new IndustryProjectCourierContract())
            ->setProject($project)
            ->setContractId($contractId)
            ->setCharacterId($characterId);
    }

    /**
     * @param array<int, bool> $hasTokenByEveCharacterId
     */
    private function ownerWithPilots(array $hasTokenByEveCharacterId): User
    {
        $owner = new User();
        foreach ($hasTokenByEveCharacterId as $eveCharacterId => $hasToken) {
            $character = (new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot '.$eveCharacterId);
            if ($hasToken) {
                $character->setEveToken((new EveToken())
                    ->setAccessToken('access-'.$eveCharacterId)
                    ->setRefreshTokenEncrypted('refresh-'.$eveCharacterId)
                    ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
                    ->setScopes(['esi-contracts.read_character_contracts.v1']));
            }
            $owner->addCharacter($character);
        }

        return $owner;
    }

    /**
     * @return array<string, mixed>
     */
    private function courierContract(int $contractId, string $status): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'courier',
            'status' => $status,
            'date_issued' => (new \DateTimeImmutable('+1 hour'))->format('c'),
            'volume' => 953.5,
            'reward' => 1_287_225.0,
            'collateral' => 22_632_500.0,
            'start_location_id' => 60_003_760,
            'end_location_id' => 1_035_466_617_946,
        ];
    }
}
