<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\AdminService;
use App\Service\Admin\SyncTracker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminService::class)]
class AdminServiceTest extends TestCase
{
    private AdminService $adminService;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(Connection::class);
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('getAll')->willReturn([]);

        $this->adminService = new AdminService(
            $this->connection,
            $syncTracker,
        );
    }

    public function testGetStatsReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('users', $stats);
        $this->assertArrayHasKey('characters', $stats);
        $this->assertArrayHasKey('tokens', $stats);
        $this->assertArrayHasKey('assets', $stats);
        $this->assertArrayHasKey('industry', $stats);
        $this->assertArrayHasKey('industryJobs', $stats);
        $this->assertArrayHasKey('syncs', $stats);
        $this->assertArrayHasKey('pve', $stats);
    }

    public function testGetStatsUsersStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('total', $stats['users']);
        $this->assertArrayHasKey('valid', $stats['users']);
        $this->assertArrayHasKey('invalid', $stats['users']);
        $this->assertArrayHasKey('activeLastWeek', $stats['users']);
        $this->assertArrayHasKey('activeLastMonth', $stats['users']);
    }

    public function testGetStatsTokensStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('total', $stats['tokens']);
        $this->assertArrayHasKey('expired', $stats['tokens']);
        $this->assertArrayHasKey('expiring24h', $stats['tokens']);
        $this->assertArrayHasKey('healthy', $stats['tokens']);
    }

    public function testGetStatsIndustryJobsStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('activeJobs', $stats['industryJobs']);
        $this->assertArrayHasKey('completedRecently', $stats['industryJobs']);
        $this->assertArrayHasKey('lastSync', $stats['industryJobs']);
    }

    public function testGetStatsSyncsStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('lastAssetSync', $stats['syncs']);
        $this->assertArrayHasKey('lastIndustrySync', $stats['syncs']);
        $this->assertArrayHasKey('structuresCached', $stats['syncs']);
        $this->assertArrayHasKey('ansiblexCount', $stats['syncs']);
    }

    public function testGetChartDataStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $charts = $this->adminService->getChartData();

        $this->assertArrayHasKey('registrations', $charts);
        $this->assertArrayHasKey('activity', $charts);
        $this->assertArrayNotHasKey('assetDistribution', $charts);

        $this->assertArrayHasKey('labels', $charts['registrations']);
        $this->assertArrayHasKey('data', $charts['registrations']);

        $this->assertArrayHasKey('labels', $charts['activity']);
        $this->assertArrayHasKey('logins', $charts['activity']);
    }

    public function testRegistrationChartLabelsAreIsoWeeksOfTheLastTwelveWeeks(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $labels = $this->adminService->getChartData()['registrations']['labels'];

        $expected = [];
        for ($weeksAgo = 11; $weeksAgo >= 0; $weeksAgo--) {
            $expected[] = (new \DateTimeImmutable("-{$weeksAgo} weeks"))->format('o-\WW');
        }
        $this->assertSame($expected, $labels);
    }

    public function testActivityChartLabelsAreIsoDatesOfTheLastSevenDays(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $labels = $this->adminService->getChartData()['activity']['labels'];

        $expected = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $expected[] = (new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d');
        }
        $this->assertSame($expected, $labels);
    }

    public function testRegistrationChartCountsEachOfTheTwelveWeeksSeparately(): void
    {
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls(...range(1, 12), ...array_fill(0, 20, 0));

        $registrations = $this->adminService->getChartData()['registrations'];

        $this->assertSame(range(1, 12), $registrations['data']);
    }

    public function testActivityChartStillCoversTheLastSevenDays(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $activity = $this->adminService->getChartData()['activity'];

        $this->assertCount(7, $activity['labels']);
        $this->assertCount(7, $activity['logins']);
    }

    public function testGetStatsPveStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0.0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->adminService->getStats();

        $this->assertArrayHasKey('totalIncome30d', $stats['pve']);
        $this->assertArrayHasKey('byCorporation', $stats['pve']);
        $this->assertIsArray($stats['pve']['byCorporation']);
    }
}
