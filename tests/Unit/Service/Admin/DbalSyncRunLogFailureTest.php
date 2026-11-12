<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\DbalSyncRunLog;
use App\Service\Admin\SyncRunLog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(DbalSyncRunLog::class)]
#[Group('issue-646')]
final class DbalSyncRunLogFailureTest extends TestCase
{
    private object $logger;
    private DbalSyncRunLog $log;

    protected function setUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('connection lost'));
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connection lost'));
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection lost'));
        $connection->method('insert')->willThrowException(new \RuntimeException('connection lost'));

        $this->logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $this->log = new DbalSyncRunLog($connection, $this->logger);
    }

    public function testAFailedStartWriteIsLoggedAsAnErrorAndNeverThrown(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable());

        self::assertSame('error', $this->logger->records[0][0] ?? null);
    }

    public function testAFailedFinishWriteIsLoggedAsAnErrorAndNeverThrown(): void
    {
        $this->log->finish('industry', SyncRunLog::STATUS_ERROR, 'boom', new \DateTimeImmutable());

        self::assertSame('error', $this->logger->records[0][0] ?? null);
    }
}
