<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use App\ApiResource\MoonTax\MoonTaxChunkMinersResource;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxDonationEntryResource;
use App\ApiResource\MoonTax\MoonTaxDonationResource;
use App\ApiResource\MoonTax\MoonTaxMineResource;
use App\ApiResource\MoonTax\MoonTaxRefineryResource;
use App\ApiResource\MoonTax\MoonTaxStatementResource;
use App\ApiResource\MoonTax\MoonTaxSyncResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-273')]
final class MoonTaxSubResourceUriVariablesTest extends TestCase
{
    private const string ROUTE_REQUIREMENT_PATTERN = '/<[^>]*>/';

    #[DataProvider('moonTaxEndpoints')]
    public function testMoonTaxEndpointDeclaresEachUriVariableAsLink(string $resourceClass, string $method, string $uriTemplate, array $expectedUriVariables): void
    {
        $operation = $this->operationOf($resourceClass, $method, $uriTemplate);

        self::assertNotNull($operation, sprintf('%s does not declare %s %s', $resourceClass, $method, $uriTemplate));
        self::assertSame($expectedUriVariables, array_keys((array) $operation->getUriVariables()));
        self::assertSame([], $this->undeclaredUriVariablesOf($operation));
    }

    public static function moonTaxEndpoints(): iterable
    {
        $corporationId = '{corporationId}';

        yield 'GET corporation' => [MoonTaxCorporationResource::class, 'GET', '/mining/moon-tax/'.$corporationId, ['corporationId']];
        yield 'POST mark paid' => [MoonTaxStatementResource::class, 'POST', '/mining/moon-tax/'.$corporationId.'/statements/{id}/mark-paid', ['corporationId', 'id']];
        yield 'GET mine' => [MoonTaxMineResource::class, 'GET', '/mining/moon-tax/mine', []];
        yield 'PUT refinery' => [MoonTaxRefineryResource::class, 'PUT', '/mining/moon-tax/'.$corporationId.'/refineries/{structureId}', ['corporationId', 'structureId']];
        yield 'POST sync' => [MoonTaxSyncResource::class, 'POST', '/mining/moon-tax/'.$corporationId.'/sync', ['corporationId']];
        yield 'GET donations' => [MoonTaxDonationEntryResource::class, 'GET', '/mining/moon-tax/'.$corporationId.'/donations', ['corporationId']];
        yield 'POST attribute donation' => [MoonTaxDonationResource::class, 'POST', '/mining/moon-tax/'.$corporationId.'/donations/{journalId}/attribute', ['corporationId', 'journalId']];
        yield 'GET chunk miners' => [MoonTaxChunkMinersResource::class, 'GET', '/mining/moon-tax/'.$corporationId.'/chunks/{id}/miners', ['corporationId', 'id']];
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
