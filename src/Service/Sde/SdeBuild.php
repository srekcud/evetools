<?php

declare(strict_types=1);

namespace App\Service\Sde;

final readonly class SdeBuild
{
    public function __construct(
        public int $buildNumber,
        public \DateTimeImmutable $releaseDate,
    ) {
    }

    public static function fromJsonLine(string $content): ?self
    {
        $firstLine = strtok($content, "\n");
        if ($firstLine === false) {
            return null;
        }

        $record = json_decode($firstLine, true);

        if (!is_array($record) || !is_int($record['buildNumber'] ?? null) || !is_string($record['releaseDate'] ?? null)) {
            return null;
        }

        try {
            $releaseDate = new \DateTimeImmutable($record['releaseDate']);
        } catch (\Exception) {
            return null;
        }

        return new self($record['buildNumber'], $releaseDate);
    }
}
