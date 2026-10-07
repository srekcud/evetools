<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\Connection as DebugConnection;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Records the SQL executed during a callback, to assert a bounded number of queries (N+1 checks).
 *
 * @property EntityManagerInterface $em
 */
trait RecordsSqlQueries
{
    /**
     * Wraps the already open driver connection with Symfony's debug middleware for the
     * duration of the callback. The profiler is not installed, so `doctrine.debug_data_holder`
     * does not exist in the test container; swapping the driver connection keeps the
     * dama transaction and the fixtures visible.
     *
     * @return list<string> SQL executed during the callback
     */
    private function sqlExecutedDuring(callable $callback): array
    {
        $connection = $this->em->getConnection();
        $connection->getNativeConnection(); // force the driver connection to be open

        $driverConnectionProperty = new \ReflectionProperty(Connection::class, '_conn');
        $driverConnection = $driverConnectionProperty->getValue($connection);
        $debugDataHolder = new DebugDataHolder();
        $driverConnectionProperty->setValue(
            $connection,
            new DebugConnection($driverConnection, $debugDataHolder, null, 'default'),
        );

        try {
            $callback();
        } finally {
            $driverConnectionProperty->setValue($connection, $driverConnection);
        }

        return array_map(
            static fn ($query): string => $query['sql'],
            $debugDataHolder->getData()['default'] ?? [],
        );
    }

    /**
     * @param list<string> $queries
     *
     * @return list<string>
     */
    private function queriesMatching(array $queries, string $tablesPattern): array
    {
        return array_values(array_filter(
            $queries,
            static fn (string $sql): bool => preg_match($tablesPattern, $sql) === 1,
        ));
    }
}
