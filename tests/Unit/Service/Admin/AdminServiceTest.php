<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminService::class)]
class AdminServiceTest extends TestCase
{
    private AdminService $adminService;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $characterRepository = $this->createMock(CharacterRepository::class);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $this->connection = $this->createMock(Connection::class);

        $this->adminService = new AdminService(
            $userRepository,
            $characterRepository,
            $assetRepository,
            $projectRepository,
            $this->connection,
        );
    }

    public function testGetStatsReturnsExpectedStructure(): void
    {
        // Mock fetchOne to return appropriate values for each query
        $this->connection
            ->method('fetchOne')
            ->willReturn(0);

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $stats = $this->adminService->getStats();

        // Verify structure
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

    public function testGetQueueStatusStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $queues = $this->adminService->getQueueStatus();

        $this->assertArrayHasKey('queues', $queues);
        $this->assertArrayHasKey('async', $queues['queues']);
        $this->assertArrayHasKey('failed', $queues['queues']);
    }

    public function testGetChartDataStructure(): void
    {
        $this->connection->method('fetchOne')->willReturn(0);

        $charts = $this->adminService->getChartData();

        $this->assertArrayHasKey('registrations', $charts);
        $this->assertArrayHasKey('activity', $charts);
        $this->assertArrayHasKey('assetDistribution', $charts);

        // Registrations structure
        $this->assertArrayHasKey('labels', $charts['registrations']);
        $this->assertArrayHasKey('data', $charts['registrations']);

        // Activity structure
        $this->assertArrayHasKey('labels', $charts['activity']);
        $this->assertArrayHasKey('logins', $charts['activity']);

        // Asset distribution structure
        $this->assertArrayHasKey('labels', $charts['assetDistribution']);
        $this->assertArrayHasKey('data', $charts['assetDistribution']);
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
