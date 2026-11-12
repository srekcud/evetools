<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
#[Group('issue-763')]
final class ComposeServicesProvideMercureEnvTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../../..';
    private const string MERCURE_CONFIG = self::PROJECT_DIR . '/config/packages/mercure.yaml';
    private const string BASE_COMPOSE = self::PROJECT_DIR . '/docker-compose.yaml';
    private const string PRODUCTION_COMPOSE = self::PROJECT_DIR . '/docker-compose.prod.yml';

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishingServices(): iterable
    {
        yield 'app' => ['app'];
        yield 'worker' => ['worker'];
    }

    public function testTheMercureConfigurationReadsTheJwtSecretFromTheEnvironment(): void
    {
        self::assertContains('MERCURE_JWT_SECRET', $this->variablesReadByTheMercureConfig());
    }

    #[DataProvider('publishingServices')]
    public function testTheServiceReceivesEveryVariableTheMercureConfigurationReads(string $service): void
    {
        $missing = array_values(array_diff(
            $this->variablesReadByTheMercureConfig(),
            array_keys($this->environmentOf($service, [self::BASE_COMPOSE])),
        ));

        self::assertSame([], $missing, sprintf('Service "%s" does not receive these Mercure variables in docker-compose.yaml.', $service));
    }

    #[DataProvider('publishingServices')]
    public function testTheServiceStillReceivesThemOnceTheProductionOverrideIsApplied(string $service): void
    {
        $missing = array_values(array_diff(
            $this->variablesReadByTheMercureConfig(),
            array_keys($this->environmentOf($service, [self::BASE_COMPOSE, self::PRODUCTION_COMPOSE])),
        ));

        self::assertSame([], $missing, sprintf('Service "%s" does not receive these Mercure variables in production.', $service));
    }

    /**
     * @return list<string>
     */
    private function variablesReadByTheMercureConfig(): array
    {
        $contents = file_get_contents(self::MERCURE_CONFIG);
        self::assertIsString($contents);
        preg_match_all('/%env\((?:[a-z_]+:)*([A-Z][A-Z0-9_]*)\)%/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param list<string> $composeFiles
     *
     * @return array<string, string>
     */
    private function environmentOf(string $service, array $composeFiles): array
    {
        $environment = [];
        foreach ($composeFiles as $composeFile) {
            $compose = Yaml::parseFile($composeFile);
            self::assertIsArray($compose);
            $entries = $compose['services'][$service]['environment'] ?? [];
            foreach ($entries as $key => $entry) {
                if (is_string($key)) {
                    $environment[$key] = (string) $entry;

                    continue;
                }
                [$name, $value] = array_pad(explode('=', (string) $entry, 2), 2, '');
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
