<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectContainerReading
{
    public const string STATUS_KNOWN = 'known';
    public const string STATUS_UNKNOWN = 'unknown';

    /**
     * @param list<ProjectContainerContent>|null $contents null when the status is unknown, never an empty list standing for "could not read"
     */
    private function __construct(
        public string $containerId,
        public string $containerName,
        public ?int $locationId,
        public string $status,
        public ?string $unknownReason,
        public ?array $contents,
        public ?\DateTimeImmutable $assetsSyncedAt,
    ) {
    }

    /**
     * @param list<ProjectContainerContent> $contents
     */
    public static function known(string $containerId, string $containerName, ?int $locationId, array $contents, ?\DateTimeImmutable $assetsSyncedAt): self
    {
        return new self($containerId, $containerName, $locationId, self::STATUS_KNOWN, null, $contents, $assetsSyncedAt);
    }

    public static function unknown(string $containerId, string $containerName, ?int $locationId, string $unknownReason, ?\DateTimeImmutable $assetsSyncedAt): self
    {
        return new self($containerId, $containerName, $locationId, self::STATUS_UNKNOWN, $unknownReason, null, $assetsSyncedAt);
    }
}
