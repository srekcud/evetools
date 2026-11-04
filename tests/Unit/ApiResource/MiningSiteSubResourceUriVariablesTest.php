<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-267')]
final class MiningSiteSubResourceUriVariablesTest extends TestCase
{
    private const string ROUTE_REQUIREMENT_PATTERN = '/<[^>]*>/';

    #[DataProvider('miningSiteEndpoints')]
    public function testMiningSiteEndpointDeclaresEachUriVariableAsLink(string $resourceClass, string $method, string $uriTemplate, array $expectedUriVariables): void
    {
        $operation = $this->operationOf($resourceClass, $method, $uriTemplate);

        self::assertNotNull($operation, sprintf('%s does not declare %s %s', $resourceClass, $method, $uriTemplate));
        self::assertSame($expectedUriVariables, array_keys((array) $operation->getUriVariables()));
        self::assertSame([], $this->undeclaredUriVariablesOf($operation));
    }

    public static function miningSiteEndpoints(): iterable
    {
        $id = '{id}';
        $siteId = '{siteId}';

        yield 'GET site' => [MiningSiteResource::class, 'GET', '/mining-sites/'.$id, ['id']];
        yield 'POST close' => [MiningSiteResource::class, 'POST', '/mining-sites/'.$id.'/close', ['id']];
        yield 'POST scan' => [MiningSiteScanResource::class, 'POST', '/mining-sites/'.$siteId.'/scans', ['siteId']];
        yield 'GET scans' => [MiningSiteScanResource::class, 'GET', '/mining-sites/'.$siteId.'/scans', ['siteId']];
        yield 'GET scan' => [MiningSiteScanResource::class, 'GET', '/mining-sites/'.$siteId.'/scans/'.$id, ['siteId', 'id']];
    }

    private function operationOf(string $resourceClass, string $method, string $uriTemplate): ?HttpOperation
    {
        foreach ((new \ReflectionClass($resourceClass))->getAttributes(ApiResource::class) as $apiResourceAttribute) {
            foreach ($apiResourceAttribute->newInstance()->getOperations() ?? [] as $operation) {
                if ($operation instanceof HttpOperation && $operation->getMethod() === $method && preg_replace(self::ROUTE_REQUIREMENT_PATTERN, '', (string) $operation->getUriTemplate()) === $uriTemplate) {
                    return $operation;
                }
            }
        }

        return null;
    }

    private function undeclaredUriVariablesOf(HttpOperation $operation): array
    {
        $detector = new SubResourceUriVariablesDetector();

        return $detector->isSubResource($operation)
            ? $detector->undeclaredUriVariables($operation)
            : array_values(array_filter(
                array_keys((array) $operation->getUriVariables()),
                static fn (string $name): bool => !(((array) $operation->getUriVariables())[$name] instanceof Link),
            ));
    }
}
