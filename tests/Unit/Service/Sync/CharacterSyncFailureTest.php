<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Enum\CharacterSyncFailureReason;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\Sync\CharacterSyncFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterSyncFailure::class)]
#[CoversClass(CharacterSyncFailureReason::class)]
#[Group('issue-197')]
final class CharacterSyncFailureTest extends TestCase
{
    private const string PILOT_TWO = 'Pilot Two';
    private const string PILOT_THREE = 'Pilot Three';

    public function testOffersExactlyTheSevenReasonsOfAnUnsyncedCharacter(): void
    {
        $this->assertSame(
            ['missing_token', 'missing_scope', 'authentication_required', 'esi_rate_limited', 'esi_unavailable', 'esi_error', 'sync_failed'],
            array_map(static fn (CharacterSyncFailureReason $reason): string => $reason->value, CharacterSyncFailureReason::cases()),
        );
    }

    public function testNamesACharacterWithoutTokenWithoutStatusCode(): void
    {
        $this->assertSame(
            ['characterName' => self::PILOT_TWO, 'reason' => 'missing_token', 'statusCode' => null],
            CharacterSyncFailure::missingToken($this->character(self::PILOT_TWO))->toArray(),
        );
    }

    public function testNamesACharacterWhoseTokenLacksTheScopeWithoutStatusCode(): void
    {
        $this->assertSame(
            ['characterName' => self::PILOT_THREE, 'reason' => 'missing_scope', 'statusCode' => null],
            CharacterSyncFailure::missingScope($this->character(self::PILOT_THREE))->toArray(),
        );
    }

    public static function provideEsiStatusCodeAndReason(): iterable
    {
        yield 'network error' => [0, 'esi_unavailable'];
        yield 'unauthorized' => [401, 'esi_error'];
        yield 'forbidden' => [403, 'esi_error'];
        yield 'not found' => [404, 'esi_error'];
        yield 'just below the error limit' => [419, 'esi_error'];
        yield 'error limit' => [420, 'esi_rate_limited'];
        yield 'just above the error limit' => [421, 'esi_error'];
        yield 'just below the rate limit' => [428, 'esi_error'];
        yield 'rate limit' => [429, 'esi_rate_limited'];
        yield 'just above the rate limit' => [430, 'esi_error'];
        yield 'last client error' => [499, 'esi_error'];
        yield 'internal server error' => [500, 'esi_unavailable'];
        yield 'bad gateway' => [502, 'esi_unavailable'];
        yield 'service unavailable' => [503, 'esi_unavailable'];
        yield 'gateway timeout' => [504, 'esi_unavailable'];
    }

    #[DataProvider('provideEsiStatusCodeAndReason')]
    public function testClassifiesAnEsiFailureByItsStatusCodeAndKeepsTheStatusCode(int $statusCode, string $expectedReason): void
    {
        $failure = CharacterSyncFailure::fromThrowable(
            $this->character(self::PILOT_TWO),
            new EsiApiException('ESI failure ' . $statusCode, $statusCode),
        );

        $this->assertSame(
            ['characterName' => self::PILOT_TWO, 'reason' => $expectedReason, 'statusCode' => $statusCode],
            $failure->toArray(),
        );
    }

    public function testClassifiesARevokedAuthorizationWithoutStatusCode(): void
    {
        $failure = CharacterSyncFailure::fromThrowable(
            $this->character(self::PILOT_THREE),
            new EveAuthRequiredException('2112000003'),
        );

        $this->assertSame(
            ['characterName' => self::PILOT_THREE, 'reason' => 'authentication_required', 'statusCode' => null],
            $failure->toArray(),
        );
    }

    public static function provideOtherThrowable(): iterable
    {
        yield 'unexpected value' => [new \UnexpectedValueException('ESI returned no corporation')];
        yield 'runtime error' => [new \RuntimeException('Database lost', 503)];
        yield 'type error' => [new \TypeError('Wrong ESI payload')];
    }

    #[DataProvider('provideOtherThrowable')]
    public function testClassifiesAnyOtherThrowableAsSyncFailedWithoutStatusCode(\Throwable $throwable): void
    {
        $failure = CharacterSyncFailure::fromThrowable($this->character(self::PILOT_TWO), $throwable);

        $this->assertSame(
            ['characterName' => self::PILOT_TWO, 'reason' => 'sync_failed', 'statusCode' => null],
            $failure->toArray(),
        );
    }

    private function character(string $name): Character
    {
        return (new Character())->setName($name);
    }
}
