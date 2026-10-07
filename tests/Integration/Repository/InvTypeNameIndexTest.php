<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #33: type names pasted by users are matched case-insensitively, so
 * `sde_inv_types` (~50k rows) needs a functional index on `lower(type_name)`.
 *
 * The test table is nearly empty, so the planner would pick a sequential scan anyway:
 * sequential scans are disabled for the test transaction (SET LOCAL, rolled back by
 * dama/doctrine-test-bundle). Without a usable index PostgreSQL still falls back to a
 * Seq Scan; with it, the plan is an (Bitmap) Index Scan. Asserting on the plan rather than
 * on `pg_indexes` checks that the index really serves the query, whatever its name.
 */
final class InvTypeNameIndexTest extends IntegrationTestCase
{
    public function testCaseInsensitiveBatchLookupOfTypeNamesUsesAnIndex(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET LOCAL enable_seqscan = off');

        $plan = implode("\n", $connection->fetchFirstColumn(
            "EXPLAIN SELECT type_id, type_name FROM sde_inv_types
             WHERE LOWER(type_name) IN (LOWER('tritanium'), LOWER('HURRICANE'), LOWER('Nanite Repair Paste'))",
        ));

        self::assertStringNotContainsString('Seq Scan on sde_inv_types', $plan, "Query plan:\n" . $plan);
        self::assertMatchesRegularExpression('/Index (Only )?Scan/', $plan, "Query plan:\n" . $plan);
    }
}
