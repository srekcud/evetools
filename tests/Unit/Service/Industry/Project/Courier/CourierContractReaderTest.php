<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\Project\Courier\CourierContractReader;
use App\Service\Industry\Project\Courier\CourierContractState;
use App\Service\Industry\Project\Courier\CourierQuote;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierContractReader::class)]
#[Group('issue-351')]
final class CourierContractReaderTest extends TestCase
{
    private const int AMBER_PILOT_ID = 2_112_351_001;
    private const int COBALT_PILOT_ID = 2_112_351_002;
    private const int JITA_STATION = 60_003_760;
    private const int WORKSHOP_STRUCTURE = 1_035_466_617_946;
    private const string PROJECT_CREATED_AT = '2026-11-01T08:00:00+00:00';

    private EsiClient&Stub $esiClient;
    private CourierContractReader $reader;
    private array $contractsByEveCharacterId = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint): array {
            preg_match('#^/characters/(\d+)/contracts/#', $endpoint, $matches);

            return $this->contractsByEveCharacterId[(int) ($matches[1] ?? 0)] ?? [];
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        $this->reader = new CourierContractReader($this->esiClient, $tokenManager);
    }

    public function testReadKeepsCourierContractsIssuedAfterTheProjectCreationNewestFirst(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $this->contractsByEveCharacterId[self::AMBER_PILOT_ID] = [
            $this->courierContract(351_001, 'in_progress', '2026-11-03T10:00:00+00:00', 2_100.5, 1_500_000.0, 90_000_000.0),
            $this->courierContract(351_002, 'finished', '2026-10-30T10:00:00+00:00', 800.0, 600_000.0, 40_000_000.0),
            $this->contractOfType('item_exchange', 351_003, '2026-11-04T10:00:00+00:00'),
            $this->courierContract(351_004, 'outstanding', '2026-11-05T10:00:00+00:00', 12.25, 7_500.0, 250_000.0),
        ];

        $read = $this->reader->read($owner, new \DateTimeImmutable(self::PROJECT_CREATED_AT));

        self::assertSame([], $read->unreadableReasonByCharacterId);
        self::assertSame(
            [
                [351_004, self::AMBER_PILOT_ID, CourierContractState::Waiting, 12.25, 7_500.0, 250_000.0, self::JITA_STATION, self::WORKSHOP_STRUCTURE, '2026-11-05T10:00:00+00:00'],
                [351_001, self::AMBER_PILOT_ID, CourierContractState::InTransit, 2_100.5, 1_500_000.0, 90_000_000.0, self::JITA_STATION, self::WORKSHOP_STRUCTURE, '2026-11-03T10:00:00+00:00'],
            ],
            array_map(static fn ($contract): array => [
                $contract->contractId, $contract->characterId, $contract->state, $contract->volume, $contract->reward,
                $contract->collateral, $contract->startLocationId, $contract->endLocationId, $contract->issuedAt->format('c'),
            ], $read->contracts),
        );
    }

    public function testCharacterWithoutTokenIsReportedUnreadableWhileTheOtherCharacterIsStillRead(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true, self::COBALT_PILOT_ID => false]);
        $this->contractsByEveCharacterId[self::AMBER_PILOT_ID] = [
            $this->courierContract(351_011, 'in_progress', '2026-11-03T10:00:00+00:00', 300.0, 45_000.0, 5_000_000.0),
        ];
        $this->contractsByEveCharacterId[self::COBALT_PILOT_ID] = [
            $this->courierContract(351_012, 'in_progress', '2026-11-04T10:00:00+00:00', 310.0, 46_000.0, 5_100_000.0),
        ];

        $read = $this->reader->read($owner, new \DateTimeImmutable(self::PROJECT_CREATED_AT));

        self::assertSame([self::COBALT_PILOT_ID => 'no_token'], $read->unreadableReasonByCharacterId);
        self::assertSame([351_011], array_map(static fn ($contract): int => $contract->contractId, $read->contracts));
    }

    public function testCandidatesAreLimitedToTheTwentyNewestCourierContracts(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $contracts = [];
        for ($day = 1; $day <= 25; ++$day) {
            $contracts[] = $this->courierContract(351_100 + $day, 'outstanding', sprintf('2026-11-%02dT12:00:00+00:00', min($day + 1, 28)), 100.0, 10_000.0, 1_000_000.0);
        }
        $this->contractsByEveCharacterId[self::AMBER_PILOT_ID] = $contracts;

        $candidates = $this->reader->candidatesOf($owner, new \DateTimeImmutable(self::PROJECT_CREATED_AT), $this->readyQuote(100.0, 10_000));

        self::assertSame(range(351_125, 351_106), array_map(static fn ($candidate): int => $candidate->contract->contractId, $candidates));
    }

    public function testCandidateMatchesTheQuoteOnlyWithinOnePercentOnVolumeAndReward(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $this->contractsByEveCharacterId[self::AMBER_PILOT_ID] = [
            $this->courierContract(351_201, 'outstanding', '2026-11-05T10:00:00+00:00', 960.0, 1_290_000.0, 1_000_000.0),
            $this->courierContract(351_202, 'outstanding', '2026-11-04T10:00:00+00:00', 970.0, 1_287_225.0, 1_000_000.0),
            $this->courierContract(351_203, 'outstanding', '2026-11-03T10:00:00+00:00', 953.5, 1_310_000.0, 1_000_000.0),
        ];

        $candidates = $this->reader->candidatesOf($owner, new \DateTimeImmutable(self::PROJECT_CREATED_AT), $this->readyQuote(953.5, 1_287_225));

        self::assertSame(
            [351_201 => true, 351_202 => false, 351_203 => false],
            array_combine(
                array_map(static fn ($candidate): int => $candidate->contract->contractId, $candidates),
                array_map(static fn ($candidate): bool => $candidate->matchesQuote, $candidates),
            ),
        );
    }

    public function testCandidateNeverMatchesAQuoteThatIsNotReady(): void
    {
        $owner = $this->ownerWithPilots([self::AMBER_PILOT_ID => true]);
        $this->contractsByEveCharacterId[self::AMBER_PILOT_ID] = [
            $this->courierContract(351_301, 'outstanding', '2026-11-05T10:00:00+00:00', 953.5, 1_287_225.0, 1_000_000.0),
        ];
        $routeUnknownQuote = new CourierQuote(953.5, 22_632_500, null, null, 'route_unknown', []);

        $candidates = $this->reader->candidatesOf($owner, new \DateTimeImmutable(self::PROJECT_CREATED_AT), $routeUnknownQuote);

        self::assertSame([[351_301, false]], array_map(static fn ($candidate): array => [$candidate->contract->contractId, $candidate->matchesQuote], $candidates));
    }

    private function readyQuote(float $volume, int $reward): CourierQuote
    {
        return new CourierQuote($volume, 22_632_500, $reward, 9, 'ready', []);
    }

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

    private function courierContract(int $contractId, string $status, string $issuedAt, float $volume, float $reward, float $collateral): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'courier',
            'status' => $status,
            'date_issued' => $issuedAt,
            'volume' => $volume,
            'reward' => $reward,
            'collateral' => $collateral,
            'start_location_id' => self::JITA_STATION,
            'end_location_id' => self::WORKSHOP_STRUCTURE,
        ];
    }

    private function contractOfType(string $type, int $contractId, string $issuedAt): array
    {
        return [
            'contract_id' => $contractId,
            'type' => $type,
            'status' => 'outstanding',
            'date_issued' => $issuedAt,
            'volume' => 1.0,
            'reward' => 1.0,
            'collateral' => 1.0,
            'start_location_id' => self::JITA_STATION,
            'end_location_id' => self::WORKSHOP_STRUCTURE,
        ];
    }
}
