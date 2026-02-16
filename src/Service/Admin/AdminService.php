<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use Doctrine\DBAL\Connection;

class AdminService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SyncTracker $syncTracker,
    ) {
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return [
            'users' => $this->getUserStats(),
            'characters' => $this->getCharacterStats(),
            'tokens' => $this->getTokenStats(),
            'assets' => $this->getAssetStats(),
            'industry' => $this->getIndustryStats(),
            'industryJobs' => $this->getIndustryJobsStats(),
            'syncs' => $this->getSyncStats(),
            'pve' => $this->getPveStats(),
            'notifications' => $this->getNotificationStats(),
            'market' => $this->getMarketStats(),
            'profitTracker' => $this->getProfitTrackerStats(),
            'schedulerHealth' => $this->syncTracker->getAll(),
        ];
    }

    /** @return array<string, int> */
    private function getUserStats(): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users');
        $valid = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE auth_status = ?',
            [User::AUTH_STATUS_VALID]
        );
        $invalid = $total - $valid;

        $oneWeekAgo = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
        $oneMonthAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        $activeLastWeek = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE last_login_at >= ?',
            [$oneWeekAgo]
        );
        $activeLastMonth = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE last_login_at >= ?',
            [$oneMonthAgo]
        );

        return [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'activeLastWeek' => $activeLastWeek,
            'activeLastMonth' => $activeLastMonth,
        ];
    }

    /** @return array<string, int> */
    private function getCharacterStats(): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM characters');
        $withValidTokens = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT c.id) FROM characters c
             JOIN eve_tokens t ON t.character_id = c.id
             JOIN users u ON c.user_id = u.id
             WHERE u.auth_status = ?',
            [User::AUTH_STATUS_VALID]
        );

        $threshold = (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s');
        $needingSync = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM characters c
             JOIN eve_tokens t ON t.character_id = c.id
             WHERE c.last_sync_at IS NULL OR c.last_sync_at < ?',
            [$threshold]
        );

        // Characters of active users (login < 7 days) with valid tokens = actual sync scope
        $activeThreshold = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
        $activeSyncScope = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT c.id) FROM characters c
             JOIN eve_tokens t ON t.character_id = c.id
             JOIN users u ON c.user_id = u.id
             WHERE u.auth_status = ? AND u.last_login_at >= ?',
            [User::AUTH_STATUS_VALID, $activeThreshold]
        );

        return [
            'total' => $total,
            'withValidTokens' => $withValidTokens,
            'needingSync' => $needingSync,
            'activeSyncScope' => $activeSyncScope,
        ];
    }

    /** @return array<string, int> */
    private function getTokenStats(): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM eve_tokens');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expired = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM eve_tokens WHERE access_token_expires_at <= ?',
            [$now]
        );

        $expiringSoon = (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
        $expiring24h = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM eve_tokens WHERE access_token_expires_at > ? AND access_token_expires_at <= ?',
            [$now, $expiringSoon]
        );

        return [
            'total' => $total,
            'expired' => $expired,
            'expiring24h' => $expiring24h,
            'healthy' => $total - $expired - $expiring24h,
        ];
    }

    /** @return array<string, mixed> */
    private function getIndustryJobsStats(): array
    {
        $activeJobs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cached_industry_jobs WHERE status = 'active'"
        );

        $oneDayAgo = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $completedRecently = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cached_industry_jobs WHERE status = 'delivered' AND end_date >= ?",
            [$oneDayAgo]
        );

        $lastSync = $this->connection->fetchOne(
            'SELECT MAX(cached_at) FROM cached_industry_jobs'
        );

        return [
            'activeJobs' => $activeJobs,
            'completedRecently' => $completedRecently,
            'lastSync' => $lastSync,
        ];
    }

    /** @return array<string, mixed> */
    private function getSyncStats(): array
    {
        // Last asset sync (most recent character sync)
        $lastAssetSync = $this->connection->fetchOne(
            'SELECT MAX(last_sync_at) FROM characters'
        );

        // Last industry job sync
        $lastIndustrySync = $this->connection->fetchOne(
            'SELECT MAX(cached_at) FROM cached_industry_jobs'
        );

        // Count structures cached
        $structuresCached = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_structure'
        );

        // Count ansiblex gates
        $ansiblexCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ansiblex_jump_gates'
        );

        // Wallet transactions
        $walletTransactionCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_wallet_transactions'
        );
        $lastWalletSync = $this->connection->fetchOne(
            'SELECT MAX(cached_at) FROM cached_wallet_transactions'
        );

        // Mining ledger
        $lastMiningSync = $this->connection->fetchOne(
            'SELECT MAX(synced_at) FROM mining_entries'
        );

        return [
            'lastAssetSync' => $lastAssetSync,
            'lastIndustrySync' => $lastIndustrySync,
            'structuresCached' => $structuresCached,
            'ansiblexCount' => $ansiblexCount,
            'walletTransactionCount' => $walletTransactionCount,
            'lastWalletSync' => $lastWalletSync,
            'lastMiningSync' => $lastMiningSync,
        ];
    }

    /** @return array<string, int> */
    private function getAssetStats(): array
    {
        $totalItems = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cached_assets');
        $personalAssets = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_assets WHERE is_corporation_asset = false'
        );
        $corporationAssets = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_assets WHERE is_corporation_asset = true'
        );

        return [
            'totalItems' => $totalItems,
            'personalAssets' => $personalAssets,
            'corporationAssets' => $corporationAssets,
        ];
    }

    /** @return array<string, int> */
    private function getIndustryStats(): array
    {
        $activeProjects = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM industry_projects WHERE status = 'active'"
        );
        $completedProjects = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM industry_projects WHERE status = 'completed'"
        );

        return [
            'activeProjects' => $activeProjects,
            'completedProjects' => $completedProjects,
        ];
    }

    /** @return array<string, mixed> */
    private function getPveStats(): array
    {
        // Get total PVE income (last 30 days)
        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $totalIncome = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM pve_income WHERE date >= ?',
            [$thirtyDaysAgo]
        );

        // Get PVE income by corporation (top 10)
        $byCorporation = $this->connection->fetchAllAssociative(
            'SELECT c.corporation_name, c.corporation_id, SUM(i.amount) as total
             FROM pve_income i
             JOIN users u ON i.user_id = u.id
             JOIN characters c ON u.main_character_id = c.id
             WHERE i.date >= ?
             GROUP BY c.corporation_id, c.corporation_name
             ORDER BY total DESC
             LIMIT 10',
            [$thirtyDaysAgo]
        );

        return [
            'totalIncome30d' => $totalIncome,
            'byCorporation' => array_map(fn($row) => [
                'corporationId' => (int) $row['corporation_id'],
                'corporationName' => $row['corporation_name'],
                'total' => (float) $row['total'],
            ], $byCorporation),
        ];
    }

    /** @return array<string, array<string, int|null>> */
    public function getQueueStatus(): array
    {
        // Try to get messenger transport status via Symfony's messenger:stats command output
        // For now, we'll query the messenger_messages table if it exists (async transport)
        $queues = [];

        try {
            $asyncCount = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'"
            );
            $queues['async'] = $asyncCount;
        } catch (\Throwable) {
            $queues['async'] = null;
        }

        try {
            $failedCount = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"
            );
            $queues['failed'] = $failedCount;
        } catch (\Throwable) {
            $queues['failed'] = null;
        }

        return [
            'queues' => $queues,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function getChartData(): array
    {
        return [
            'registrations' => $this->getRegistrationChartData(),
            'activity' => $this->getActivityChartData(),
            'assetDistribution' => $this->getAssetDistributionData(),
        ];
    }

    /** @return array<string, list<string|int>> */
    private function getRegistrationChartData(): array
    {
        $labels = [];
        $data = [];

        // Get registrations per week for the last 4 weeks
        for ($i = 3; $i >= 0; $i--) {
            $weekStart = (new \DateTimeImmutable("-{$i} weeks"))->modify('monday this week');
            $weekEnd = $weekStart->modify('+7 days');

            $labels[] = 'Sem ' . $weekStart->format('W');
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?',
                [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]
            );
            $data[] = $count;
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /** @return array<string, list<string|int>> */
    private function getActivityChartData(): array
    {
        $labels = [];
        $logins = [];

        // Get logins per day for the last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("-{$i} days"));
            $dayStart = $day->setTime(0, 0, 0);
            $dayEnd = $day->setTime(23, 59, 59);

            $dayNames = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
            $labels[] = $dayNames[(int) $day->format('w')];

            $loginCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM users WHERE last_login_at >= ? AND last_login_at <= ?',
                [$dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s')]
            );
            $logins[] = $loginCount;
        }

        return [
            'labels' => $labels,
            'logins' => $logins,
        ];
    }

    /** @return array<string, list<string|int>> */
    private function getAssetDistributionData(): array
    {
        $personal = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_assets WHERE is_corporation_asset = false'
        );
        $corporation = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cached_assets WHERE is_corporation_asset = true'
        );

        return [
            'labels' => ['Personnel', 'Corporation'],
            'data' => [$personal, $corporation],
        ];
    }

    /** @return array<string, int> */
    private function getNotificationStats(): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notifications');
        $unread = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE is_read = false'
        );
        $pushSubscriptions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM push_subscriptions'
        );
        $preferences = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_notification_preferences'
        );

        return [
            'total' => $total,
            'unread' => $unread,
            'pushSubscriptions' => $pushSubscriptions,
            'preferences' => $preferences,
        ];
    }

    /** @return array<string, int|float> */
    private function getProfitTrackerStats(): array
    {
        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        $totalMatches = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM profit_matches WHERE matched_at >= ?',
            [$thirtyDaysAgo]
        );
        $totalProfit = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(profit), 0) FROM profit_matches WHERE matched_at >= ?',
            [$thirtyDaysAgo]
        );
        $itemsTracked = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT product_type_id) FROM profit_matches WHERE matched_at >= ?',
            [$thirtyDaysAgo]
        );

        return [
            'totalMatches' => $totalMatches,
            'totalProfit30d' => round($totalProfit, 2),
            'itemsTracked' => $itemsTracked,
        ];
    }

    /** @return array<string, int> */
    private function getMarketStats(): array
    {
        $historyTypes = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT type_id) FROM market_price_history'
        );
        $historyEntries = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM market_price_history'
        );
        $alertsActive = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM market_price_alerts WHERE status = 'active'"
        );
        $alertsTriggered = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM market_price_alerts WHERE status = 'triggered'"
        );
        $favorites = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM market_favorites'
        );

        return [
            'historyTypes' => $historyTypes,
            'historyEntries' => $historyEntries,
            'alertsActive' => $alertsActive,
            'alertsTriggered' => $alertsTriggered,
            'favorites' => $favorites,
        ];
    }
}
