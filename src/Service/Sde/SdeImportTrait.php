<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared utilities for SDE importers: JSONL reading, localized string extraction, table truncation.
 */
trait SdeImportTrait
{
    abstract private function getEntityManager(): EntityManagerInterface;

    abstract private function getLogger(): LoggerInterface;

    abstract private function getTempDir(): string;

    /**
     * @return \Generator<int|string, array<string, mixed>>
     */
    private function readJsonlFile(string $filename): \Generator
    {
        $path = $this->getTempDir() . '/' . $filename;
        if (!file_exists($path)) {
            throw new \RuntimeException("SDE file not found: {$filename}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open SDE file: {$filename}");
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            $key = $data['_key'];
            unset($data['_key']);
            yield $key => $data;
        }
        fclose($handle);
    }

    /** @param array<string, mixed> $data */
    private function getName(array $data): string
    {
        if (isset($data['name'])) {
            if (is_string($data['name'])) {
                return $data['name'];
            }
            if (is_int($data['name']) || is_float($data['name'])) {
                return (string) $data['name'];
            }
            if (is_array($data['name'])) {
                $value = $data['name']['en'] ?? reset($data['name']) ?? '';

                return is_string($value) ? $value : (string) $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function getString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        if (is_string($value)) {
            return $value ?: null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $resolved = $value['en'] ?? reset($value) ?? null;

            return is_string($resolved) ? $resolved : ($resolved !== null ? (string) $resolved : null);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function getDescription(array $data): ?string
    {
        if (isset($data['description'])) {
            if (is_string($data['description'])) {
                return $data['description'] ?: null;
            }
            if (is_array($data['description'])) {
                return $data['description']['en'] ?? reset($data['description']) ?? null;
            }
        }

        return null;
    }

    private function truncateTable(string $tableName): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();

        $connection->executeStatement('SET session_replication_role = replica');
        $connection->executeStatement($platform->getTruncateTableSQL($tableName, true));
        $connection->executeStatement('SET session_replication_role = DEFAULT');
    }

    private function notify(?callable $callback, string $message): void
    {
        $this->getLogger()->info($message);
        if ($callback) {
            $callback($message);
        }
    }

    private function getConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
