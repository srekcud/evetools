<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\CorporationService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationService::class)]
class CorporationServiceTest extends TestCase
{
    private const int CORPORATION_ID = 98000001;
    private const int EVE_CHARACTER_ID = 2112000001;
    private const string DIVISIONS_ENDPOINT = '/corporations/98000001/divisions/';

    private EsiClient&MockObject $esiClient;
    private CorporationService $corporationService;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->corporationService = new CorporationService($this->esiClient);
    }

    // ===========================================
    // getDivisions — success path (guards)
    // ===========================================

    public function testGetDivisionsReturnsHangarDivisionNamesIndexedByDivisionNumber(): void
    {
        $token = $this->createStub(EveToken::class);
        $director = $this->createDirector($token);

        $this->esiClient->expects($this->once())
            ->method('get')
            ->with(self::DIVISIONS_ENDPOINT, $token)
            ->willReturn([
                'hangar' => [
                    ['division' => 1, 'name' => 'Minerals'],
                    ['division' => 2, 'name' => 'Ships'],
                    ['division' => 3],
                ],
                'wallet' => [
                    ['division' => 1, 'name' => 'Master Wallet'],
                ],
            ]);

        $divisions = $this->corporationService->getDivisions($director);

        $this->assertSame([1 => 'Minerals', 2 => 'Ships', 3 => 'Division 3'], $divisions);
    }

    public function testGetDivisionsReturnsEmptyListWhenEsiReturnsNoHangarDivision(): void
    {
        $director = $this->createDirector($this->createStub(EveToken::class));

        $this->esiClient->expects($this->once())->method('get')->willReturn(['hangar' => [], 'wallet' => []]);

        $this->assertSame([], $this->corporationService->getDivisions($director));
    }

    public function testGetDivisionsReturnsEmptyListWhenEsiResponseHasNoHangarKey(): void
    {
        $director = $this->createDirector($this->createStub(EveToken::class));

        $this->esiClient->expects($this->once())->method('get')->willReturn([]);

        $this->assertSame([], $this->corporationService->getDivisions($director));
    }

    // ===========================================
    // getDivisions — errors are propagated (issue #14 follow-up)
    // ===========================================

    /**
     * @return iterable<string, array{EsiApiException}>
     */
    public static function esiFailures(): iterable
    {
        yield 'ESI unavailable (503)' => [EsiApiException::fromResponse(503, 'ESI request failed', self::DIVISIONS_ENDPOINT)];
        yield 'director role lost (403)' => [EsiApiException::forbidden('Character does not have required role(s)', self::DIVISIONS_ENDPOINT)];
        yield 'network error (0)' => [EsiApiException::fromResponse(0, 'Network error: timeout', self::DIVISIONS_ENDPOINT)];
    }

    #[DataProvider('esiFailures')]
    public function testGetDivisionsPropagatesEsiFailureInsteadOfReturningEmptyList(EsiApiException $esiFailure): void
    {
        $director = $this->createDirector($this->createStub(EveToken::class));

        $this->esiClient->method('get')->willThrowException($esiFailure);

        try {
            $divisions = $this->corporationService->getDivisions($director);
        } catch (EsiApiException $caught) {
            $this->assertSame($esiFailure, $caught);

            return;
        }

        $this->fail(sprintf(
            'getDivisions() must propagate the ESI failure (HTTP %d), it returned %s instead',
            $esiFailure->statusCode,
            json_encode($divisions),
        ));
    }

    public function testGetDivisionsThrowsEveAuthRequiredWhenCharacterHasNoToken(): void
    {
        $director = $this->createDirector(null);

        $this->esiClient->expects($this->never())->method('get');

        try {
            $divisions = $this->corporationService->getDivisions($director);
        } catch (EveAuthRequiredException $caught) {
            $this->assertSame((string) self::EVE_CHARACTER_ID, $caught->characterId);

            return;
        }

        $this->fail(sprintf(
            'getDivisions() must throw EveAuthRequiredException when the character has no token, it returned %s instead',
            json_encode($divisions),
        ));
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createDirector(?EveToken $token): Character&Stub
    {
        $director = $this->createStub(Character::class);
        $director->method('getEveToken')->willReturn($token);
        $director->method('getCorporationId')->willReturn(self::CORPORATION_ID);
        $director->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        return $director;
    }
}
