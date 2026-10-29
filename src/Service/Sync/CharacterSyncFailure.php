<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Enum\CharacterSyncFailureReason;

final readonly class CharacterSyncFailure
{
    private function __construct(
        public string $characterName,
        public CharacterSyncFailureReason $reason,
        public ?int $statusCode,
    ) {
    }

    public static function missingToken(Character $character): self
    {
        return new self('', CharacterSyncFailureReason::SyncFailed, -1);
    }

    public static function missingScope(Character $character): self
    {
        return new self('', CharacterSyncFailureReason::SyncFailed, -1);
    }

    public static function fromThrowable(Character $character, \Throwable $throwable): self
    {
        return new self('', CharacterSyncFailureReason::SyncFailed, -1);
    }

    /**
     * @return array{characterName: string, reason: string, statusCode: int|null}|array{}
     */
    public function toArray(): array
    {
        return [];
    }
}
