<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-838')]
final class OnDemandWorkerComposeTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../../..';
    private const string BASE_COMPOSE = self::PROJECT_DIR . '/docker-compose.yaml';
    private const string PRODUCTION_COMPOSE = self::PROJECT_DIR . '/docker-compose.prod.yml';

    public function testTheOnDemandWorkerConsumesOnlyTheOnDemandTransport(): void
    {
        $service = $this->service(self::BASE_COMPOSE, 'worker-on-demand');

        self::assertSame(
            ['bin/console', 'messenger:consume', 'on_demand', '--time-limit=3600', '--memory-limit=256M'],
            $service['command'] ?? null,
        );
    }

    public function testTheOnDemandWorkerRunsTheApplicationImageThroughPhp(): void
    {
        $service = $this->service(self::BASE_COMPOSE, 'worker-on-demand');

        self::assertSame('evetools-app', $service['image'] ?? null);
        self::assertSame(['php'], $service['entrypoint'] ?? null);
    }

    public function testTheOnDemandWorkerHasItsOwnAddressOnTheApplicationNetwork(): void
    {
        $worker = $this->service(self::BASE_COMPOSE, 'worker');
        $onDemandWorker = $this->service(self::BASE_COMPOSE, 'worker-on-demand');

        $onDemandAddress = $onDemandWorker['networks']['evetools_net']['ipv4_address'] ?? null;
        self::assertIsString($onDemandAddress);
        self::assertNotSame($worker['networks']['evetools_net']['ipv4_address'], $onDemandAddress);
    }

    public function testTheOnDemandWorkerSharesTheEnvironmentHealthcheckAndDependenciesOfTheWorker(): void
    {
        $worker = $this->service(self::BASE_COMPOSE, 'worker');
        $onDemandWorker = $this->service(self::BASE_COMPOSE, 'worker-on-demand');

        self::assertSame($worker['environment'], $onDemandWorker['environment'] ?? null);
        self::assertSame($worker['healthcheck'] ?? null, $onDemandWorker['healthcheck'] ?? null);
        self::assertSame($worker['depends_on'] ?? null, $onDemandWorker['depends_on'] ?? null);
    }

    public function testTheExistingWorkerKeepsTheAsyncAndSchedulerTransports(): void
    {
        $service = $this->service(self::BASE_COMPOSE, 'worker');

        self::assertSame(
            ['bin/console', 'messenger:consume', 'async', 'scheduler_default', '--time-limit=3600', '--memory-limit=256M'],
            $service['command'] ?? null,
        );
    }

    public function testTheOnDemandWorkerUsesThePublishedImageAndTheWorkerMemoryLimitsInProduction(): void
    {
        $worker = $this->service(self::PRODUCTION_COMPOSE, 'worker');
        $onDemandWorker = $this->service(self::PRODUCTION_COMPOSE, 'worker-on-demand');

        self::assertSame($worker['image'], $onDemandWorker['image'] ?? null);
        self::assertStringStartsWith('ghcr.io/srekcud/evetools-app:${APP_VERSION:?', (string) ($onDemandWorker['image'] ?? ''));
        self::assertSame(
            ['-d', 'memory_limit=448M', 'bin/console', 'messenger:consume', 'on_demand', '--time-limit=3600', '--memory-limit=256M'],
            $onDemandWorker['command'] ?? null,
        );
        self::assertSame('512M', $onDemandWorker['deploy']['resources']['limits']['memory'] ?? null);
        self::assertSame($worker['environment'], $onDemandWorker['environment'] ?? null);
    }

    /** @return array<string, mixed> */
    private function service(string $composeFile, string $service): array
    {
        $compose = Yaml::parseFile($composeFile);
        self::assertIsArray($compose);

        return $compose['services'][$service] ?? [];
    }
}
