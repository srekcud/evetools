<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Enum\CharacterSyncFailureReason;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;

final readonly class CharacterSyncFailure
{
    private const int NETWORK_ERROR_STATUS_CODE = 0;
    private const int ESI_ERROR_LIMITED_STATUS_CODE = 420;
    private const int ESI_RATE_LIMITED_STATUS_CODE = 429;
    private const int FIRST_SERVER_ERROR_STATUS_CODE = 500;

    private function __construct(
        public string $characterName,
        public CharacterSyncFailureReason $reason,
        public ?int $statusCode,
        public ?int $corporationId = null,
    ) {
    }

    public static function missingToken(Character $character): self
    {
        return new self($character->getName(), CharacterSyncFailureReason::MissingToken, null);
    }

    public static function missingScope(Character $character): self
    {
        return new self($character->getName(), CharacterSyncFailureReason::MissingScope, null);
    }

    public static function noDirector(int $corporationId, string $corporationName): self
    {
        return new self($corporationName, CharacterSyncFailureReason::NoDirector, null, $corporationId);
    }

    public static function fromThrowable(Character $character, \Throwable $throwable): self
    {
        if ($throwable instanceof EsiApiException) {
            return new self($character->getName(), self::esiReason($throwable->statusCode), $throwable->statusCode);
        }

        $reason = $throwable instanceof EveAuthRequiredException
            ? CharacterSyncFailureReason::AuthenticationRequired
            : CharacterSyncFailureReason::SyncFailed;

        return new self($character->getName(), $reason, null);
    }

    /**
     * @return array{characterName: string, reason: string, statusCode: int|null, corporationId?: int}
     */
    public function toArray(): array
    {
        $payload = [
            'characterName' => $this->characterName,
            'reason' => $this->reason->value,
            'statusCode' => $this->statusCode,
        ];
        if ($this->corporationId !== null) {
            $payload['corporationId'] = $this->corporationId;
        }

        return $payload;
    }

    /**
     * @return array{characterName: string, error: string, statusCode?: int}
     */
    public function logContext(string $error): array
    {
        $context = ['characterName' => $this->characterName, 'error' => $error];
        if ($this->statusCode !== null) {
            $context['statusCode'] = $this->statusCode;
        }

        return $context;
    }

    private static function esiReason(int $statusCode): CharacterSyncFailureReason
    {
        return match (true) {
            $statusCode === self::ESI_ERROR_LIMITED_STATUS_CODE,
            $statusCode === self::ESI_RATE_LIMITED_STATUS_CODE => CharacterSyncFailureReason::EsiRateLimited,
            $statusCode === self::NETWORK_ERROR_STATUS_CODE,
            $statusCode >= self::FIRST_SERVER_ERROR_STATUS_CODE => CharacterSyncFailureReason::EsiUnavailable,
            default => CharacterSyncFailureReason::EsiError,
        };
    }
}
