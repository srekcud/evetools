<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infra;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-990')]
final class DeployScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/deploy';
    private const string NEW_VERSION = 'V0.23';
    private const string PREVIOUS_VERSION = 'V0.22';
    private const string APP_IMAGE = 'ghcr.io/srekcud/evetools-app:' . self::NEW_VERSION;
    private const string PROXY_IMAGE = 'ghcr.io/srekcud/evetools-proxy:' . self::NEW_VERSION;

    private const array DEFAULT_SERVICES = [
        'app' => self::APP_IMAGE,
        'worker' => self::APP_IMAGE,
        'worker-on-demand' => self::APP_IMAGE,
        'proxy' => self::PROXY_IMAGE,
        'postgres' => 'postgres:16-alpine',
        'rabbitmq' => 'rabbitmq:4-alpine',
        'redis' => 'redis:8-alpine',
    ];

    private const string FAKE_DOCKER = <<<'BASH'
#!/usr/bin/env bash
state="$FAKE_STATE"
if [[ "$1" == compose ]]; then
    shift 5
    echo "$*" >> "$state/calls.log"
    subcommand="$1"
    shift
    case "$subcommand" in
        config) cat "$state/config.yaml" ;;
        ps)
            service="${!#}"
            if [[ -f "$state/containers/$service" ]]; then cat "$state/containers/$service"; fi
            exit 0
            ;;
        exec) echo "dump" ;;
        run) exit "$(cat "$state/migrate-exit" 2>/dev/null || echo 0)" ;;
        up)
            force=0
            services=()
            while (($#)); do
                case "$1" in
                    --force-recreate) force=1 ;;
                    --wait-timeout|--pull) shift ;;
                    -*) ;;
                    *) services+=("$1") ;;
                esac
                shift
            done
            for service in "${services[@]}"; do
                if ((force)) || [[ ! -f "$state/containers/$service" ]] || grep -qx "$service" "$state/changed" 2>/dev/null; then
                    echo "container-$RANDOM-$RANDOM" > "$state/containers/$service"
                fi
            done
            ;;
    esac
    exit 0
fi
echo "$*" >> "$state/calls.log"
exit 0
BASH;

    private string $root;

    protected function setUp(): void
    {
        if ('' === trim((string) shell_exec('command -v bash'))) {
            self::markTestSkipped('bin/deploy needs bash, absent from the Alpine application image');
        }
        $this->root = sys_get_temp_dir() . '/deploy-script-' . bin2hex(random_bytes(4));
        foreach (['/app/bin', '/stubs', '/state/containers', '/backups'] as $directory) {
            mkdir($this->root . $directory, 0777, true);
        }
        copy(self::SCRIPT, $this->root . '/app/bin/deploy');
        chmod($this->root . '/app/bin/deploy', 0755);
        $this->writeStub('docker', self::FAKE_DOCKER);
        $this->writeStub('git', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeStub('flock', "#!/usr/bin/env bash\nexit 0\n");
        file_put_contents($this->root . '/app/.deployed-version', self::PREVIOUS_VERSION . "\n");
        file_put_contents($this->root . '/state/calls.log', '');
        $this->composeConfig(self::DEFAULT_SERVICES);
        $this->existingContainers(array_keys(self::DEFAULT_SERVICES));
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testEveryServiceBuiltFromTheAppImageIsStoppedBeforeTheMigrations(): void
    {
        $this->deploy();

        $stop = $this->singleCall('stop');
        self::assertSame(['app', 'worker', 'worker-on-demand'], $this->sortedServicesOf($stop));
        self::assertLessThan($this->indexOfCall('run'), $this->indexOfCall('stop'));
    }

    public function testEveryServiceBuiltFromTheAppImageIsRecreatedOnTheNewVersion(): void
    {
        $this->deploy();

        $up = $this->forceRecreateCallWith('app');
        self::assertSame(['app', 'worker', 'worker-on-demand'], $this->sortedServicesOf($up));
        self::assertGreaterThan($this->indexOfCall('run'), $this->indexOfCallContaining($up));
    }

    public function testAWorkerAddedToTheComposeFilesIsHandledWithoutEditingTheScript(): void
    {
        $this->composeConfig(self::DEFAULT_SERVICES + ['worker-reports' => self::APP_IMAGE]);
        $this->existingContainers(array_keys(self::DEFAULT_SERVICES));

        $this->deploy();

        self::assertContains('worker-reports', $this->sortedServicesOf($this->forceRecreateCallWith('app')));
        self::assertNotContains('worker-reports', $this->sortedServicesOf($this->singleCall('stop')));
    }

    public function testInfraServicesFollowTheirComposeDefinitionWithoutBeingForceRecreated(): void
    {
        $this->deploy();

        $infraUp = $this->callWithServices('up', ['postgres', 'rabbitmq', 'redis']);
        self::assertStringNotContainsString('--force-recreate', $infraUp);
        self::assertStringContainsString('--wait', $infraUp);
        self::assertStringNotContainsString('proxy', $infraUp);
    }

    public function testInfraIsBroughtUpAfterTheBackupAndTheStopAndBeforeTheMigrations(): void
    {
        $this->deploy();

        $infraIndex = $this->indexOfCallContaining($this->callWithServices('up', ['postgres', 'rabbitmq', 'redis']));
        self::assertGreaterThan($this->indexOfCall('exec'), $infraIndex);
        self::assertGreaterThan($this->indexOfCall('stop'), $infraIndex);
        self::assertLessThan($this->indexOfCall('run'), $infraIndex);
    }

    public function testTheLogNamesTheInfraContainersThatWereRecreated(): void
    {
        file_put_contents($this->root . '/state/changed', "redis\n");

        $result = $this->deploy();

        self::assertMatchesRegularExpression('/^==> infra redis: recreated$/m', $result['output']);
        self::assertMatchesRegularExpression('/^==> infra postgres: unchanged$/m', $result['output']);
        self::assertMatchesRegularExpression('/^==> infra rabbitmq: unchanged$/m', $result['output']);
    }

    public function testTheLogNamesTheInfraContainersThatWereCreated(): void
    {
        $this->existingContainers(['app', 'worker', 'worker-on-demand', 'proxy', 'postgres', 'rabbitmq']);

        $result = $this->deploy();

        self::assertMatchesRegularExpression('/^==> infra redis: created$/m', $result['output']);
        self::assertMatchesRegularExpression('/^==> infra postgres: unchanged$/m', $result['output']);
    }

    public function testTheProxyIsRecreatedLastAndTheVersionIsRecorded(): void
    {
        $result = $this->deploy();

        $calls = $this->calls();
        $lastUp = '';
        foreach ($calls as $call) {
            if (str_starts_with($call, 'up ')) {
                $lastUp = $call;
            }
        }
        self::assertSame(0, $result['status']);
        self::assertSame(['proxy'], $this->sortedServicesOf($lastUp));
        self::assertStringContainsString('--force-recreate', $lastUp);
        self::assertSame(self::NEW_VERSION, trim((string) file_get_contents($this->root . '/app/.deployed-version')));
    }

    public function testAFailedMigrationRestartsOnlyTheApplicationServicesThatExisted(): void
    {
        file_put_contents($this->root . '/state/migrate-exit', '1');
        $this->existingContainers(['app', 'worker', 'proxy', 'postgres', 'rabbitmq', 'redis']);

        $result = $this->deploy();

        self::assertSame(1, $result['status']);
        self::assertSame(['app', 'worker'], $this->sortedServicesOf($this->singleCall('start')));
        self::assertSame(['app', 'worker'], $this->sortedServicesOf($this->singleCall('stop')));
        self::assertSame(self::PREVIOUS_VERSION, trim((string) file_get_contents($this->root . '/app/.deployed-version')));
    }

    public function testNoStopIsIssuedWhenNoApplicationContainerExists(): void
    {
        $this->existingContainers(['proxy', 'postgres', 'rabbitmq', 'redis']);

        $result = $this->deploy();

        self::assertSame(0, $result['status']);
        self::assertSame([], $this->callsStartingWith('stop'));
        self::assertSame(['app', 'worker', 'worker-on-demand'], $this->sortedServicesOf($this->forceRecreateCallWith('app')));
    }

    public function testTheDeployStopsBeforeTouchingAnythingWhenNoServiceUsesTheAppImage(): void
    {
        $this->composeConfig(array_diff_key(self::DEFAULT_SERVICES, ['app' => 1, 'worker' => 1, 'worker-on-demand' => 1]));

        $result = $this->deploy();

        self::assertNotSame(0, $result['status']);
        self::assertSame([], $this->callsStartingWith('stop'));
        self::assertSame([], $this->callsStartingWith('up'));
        self::assertSame([], $this->callsStartingWith('run'));
    }

    /**
     * @return array{status: int, output: string}
     */
    private function deploy(): array
    {
        $process = proc_open(
            ['bash', $this->root . '/app/bin/deploy', self::NEW_VERSION],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [
                'PATH' => $this->root . '/stubs:' . getenv('PATH'),
                'HOME' => $this->root,
                'FAKE_STATE' => $this->root . '/state',
                'BACKUP_DIR' => $this->root . '/backups',
            ],
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    /**
     * @param array<string, string> $services
     */
    private function composeConfig(array $services): void
    {
        $yaml = "name: evetools\nservices:\n";
        $address = 5;
        foreach ($services as $name => $image) {
            $yaml .= "  {$name}:\n"
                . "    depends_on:\n      postgres:\n        condition: service_healthy\n"
                . "    healthcheck:\n      test:\n        - CMD\n        - curl\n"
                . "    image: {$image}\n"
                . "    networks:\n      evetools_net:\n        ipv4_address: 10.169.0." . $address++ . "\n";
        }
        $yaml .= "networks:\n  evetools_net:\n    name: evetools_evetools_net\n    driver: bridge\n"
            . "volumes:\n  postgres_data:\n    name: evetools_postgres_data\n";
        file_put_contents($this->root . '/state/config.yaml', $yaml);
    }

    /**
     * @param list<string> $services
     */
    private function existingContainers(array $services): void
    {
        foreach (glob($this->root . '/state/containers/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ($services as $service) {
            file_put_contents($this->root . '/state/containers/' . $service, 'container-initial-' . $service . "\n");
        }
    }

    private function writeStub(string $name, string $content): void
    {
        file_put_contents($this->root . '/stubs/' . $name, $content);
        chmod($this->root . '/stubs/' . $name, 0755);
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        return array_values(array_filter(
            explode("\n", (string) file_get_contents($this->root . '/state/calls.log')),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /**
     * @return list<string>
     */
    private function callsStartingWith(string $subcommand): array
    {
        return array_values(array_filter(
            $this->calls(),
            static fn (string $call): bool => str_starts_with($call, $subcommand . ' '),
        ));
    }

    private function singleCall(string $subcommand): string
    {
        $calls = $this->callsStartingWith($subcommand);
        self::assertCount(1, $calls, sprintf('expected exactly one "%s" call, got: %s', $subcommand, implode(' | ', $this->calls())));

        return $calls[0];
    }

    private function indexOfCall(string $subcommand): int
    {
        return $this->indexOfCallContaining($this->callsStartingWith($subcommand)[0] ?? '');
    }

    private function indexOfCallContaining(string $call): int
    {
        $index = array_search($call, $this->calls(), true);
        self::assertNotFalse($index, sprintf('call "%s" not found', $call));

        return $index;
    }

    private function forceRecreateCallWith(string $service): string
    {
        foreach ($this->callsStartingWith('up') as $call) {
            if (str_contains($call, '--force-recreate') && in_array($service, $this->servicesOf($call), true)) {
                return $call;
            }
        }
        self::fail(sprintf('no forced "up" call for %s, got: %s', $service, implode(' | ', $this->calls())));
    }

    /**
     * @param list<string> $services
     */
    private function callWithServices(string $subcommand, array $services): string
    {
        sort($services);
        foreach ($this->callsStartingWith($subcommand) as $call) {
            if ($this->sortedServicesOf($call) === $services) {
                return $call;
            }
        }
        self::fail(sprintf('no "%s" call for %s, got: %s', $subcommand, implode(',', $services), implode(' | ', $this->calls())));
    }

    /**
     * @return list<string>
     */
    private function sortedServicesOf(string $call): array
    {
        $services = $this->servicesOf($call);
        sort($services);

        return $services;
    }

    /**
     * @return list<string>
     */
    private function servicesOf(string $call): array
    {
        $tokens = explode(' ', $call);
        array_shift($tokens);
        $services = [];
        $skipNext = false;
        foreach ($tokens as $token) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (in_array($token, ['--wait-timeout', '--pull'], true)) {
                $skipNext = true;
                continue;
            }
            if (!str_starts_with($token, '-')) {
                $services[] = $token;
            }
        }

        return $services;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
