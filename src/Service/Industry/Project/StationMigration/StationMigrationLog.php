<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

use App\Exception\InvalidStationMigrationLogException;

final readonly class StationMigrationLog
{
    /**
     * @param list<StationMigrationChange> $changes
     */
    public function write(string $path, array $changes, \DateTimeImmutable $appliedAt): void
    {
        $content = json_encode([
            'appliedAt' => $appliedAt->format(\DATE_ATOM),
            'changes' => array_map(
                static fn (StationMigrationChange $change): array => [
                    'projectId' => $change->projectId,
                    'kind' => $change->kind->value,
                    'subject' => $change->subject,
                    'value' => $change->value,
                ],
                $changes,
            ),
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        if (false === @file_put_contents($path, $content)) {
            throw new \RuntimeException(\sprintf('The station migration log "%s" cannot be written.', $path));
        }
    }

    /**
     * @return list<StationMigrationChange>
     *
     * @throws InvalidStationMigrationLogException
     */
    public function read(string $path): array
    {
        $content = is_file($path) ? @file_get_contents($path) : false;
        if (false === $content) {
            throw new InvalidStationMigrationLogException(\sprintf('The station migration log "%s" cannot be read.', $path));
        }

        try {
            $document = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidStationMigrationLogException('The station migration log is not valid JSON.', 0, $e);
        }

        if (!\is_array($document) || !isset($document['changes']) || !\is_array($document['changes'])) {
            throw new InvalidStationMigrationLogException('The station migration log has no list of changes.');
        }

        return array_values(array_map($this->changeOf(...), $document['changes']));
    }

    private function changeOf(mixed $entry): StationMigrationChange
    {
        if (!\is_array($entry)) {
            throw new InvalidStationMigrationLogException('A change of the station migration log is not an object.');
        }

        foreach (['projectId', 'kind', 'subject', 'value'] as $field) {
            if (!isset($entry[$field]) || !\is_string($entry[$field])) {
                throw new InvalidStationMigrationLogException(\sprintf('A change of the station migration log has no "%s" text.', $field));
            }
        }

        $kind = StationMigrationChangeKind::tryFrom($entry['kind'])
            ?? throw new InvalidStationMigrationLogException(\sprintf('"%s" is not a kind of change.', $entry['kind']));

        return new StationMigrationChange($entry['projectId'], $kind, $entry['subject'], $entry['value']);
    }
}
