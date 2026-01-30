<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class EveTokenDto
{
    /**
     * @param array<string> $scopes
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public array $scopes,
    ) {
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable("+{$this->expiresIn} seconds");
    }
}
