<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class CandidateProject
{
    /**
     * @param list<string>|null $producerInstallerIds
     * @param list<int>|null    $containerItemIds
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ?array $producerInstallerIds = null,
        public ?array $containerItemIds = null,
    ) {
    }
}
