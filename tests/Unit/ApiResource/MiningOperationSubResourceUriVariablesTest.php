<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use App\ApiResource\MiningOperation\MiningOperationCharacterResource;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\ApiResource\MiningOperation\MiningOperationResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-262')]
final class MiningOperationSubResourceUriVariablesTest extends TestCase
{
    private const string ROUTE_REQUIREMENT_PATTERN = '/<[^>]*>/';

    #[DataProvider('miningOperationEndpoints')]
    public function testMiningOperationEndpointDeclaresEachUriVariableAsLink(string $resourceClass, string $method, string $uriTemplate, array $expectedUriVariables): void
    {
        $operation = $this->operationOf($resourceClass, $method, $uriTemplate);

        self::assertNotNull($operation, sprintf('%s does not declare %s %s', $resourceClass, $method, $uriTemplate));
        self::assertSame($expectedUriVariables, array_keys((array) $operation->getUriVariables()));
        self::assertSame([], $this->undeclaredUriVariablesOf($operation));
    }

    public static function miningOperationEndpoints(): iterable
    {
        $id = '{id}';
        $operationId = '{operationId}';

        yield 'GET operation' => [MiningOperationResource::class, 'GET', '/mining-operations/'.$id, ['id']];
        yield 'DELETE operation' => [MiningOperationResource::class, 'DELETE', '/mining-operations/'.$id, ['id']];
        yield 'POST join code' => [MiningOperationResource::class, 'POST', '/mining-operations/'.$id.'/join-code', ['id']];
        yield 'POST leave' => [MiningOperationResource::class, 'POST', '/mining-operations/'.$id.'/leave', ['id']];
        yield 'GET invitation' => [MiningOperationInvitationResource::class, 'GET', '/mining-operations/join/{code}', ['code']];
        yield 'POST invitation' => [MiningOperationInvitationResource::class, 'POST', '/mining-operations/join/{code}', ['code']];
        yield 'GET members' => [MiningOperationMemberResource::class, 'GET', '/mining-operations/'.$operationId.'/members', ['operationId']];
        yield 'PATCH member' => [MiningOperationMemberResource::class, 'PATCH', '/mining-operations/'.$operationId.'/members/'.$id, ['operationId', 'id']];
        yield 'DELETE member' => [MiningOperationMemberResource::class, 'DELETE', '/mining-operations/'.$operationId.'/members/'.$id, ['operationId', 'id']];
        yield 'POST start' => [MiningOperationResource::class, 'POST', '/mining-operations/'.$id.'/start', ['id']];
        yield 'POST end' => [MiningOperationResource::class, 'POST', '/mining-operations/'.$id.'/end', ['id']];
        yield 'POST depart' => [MiningOperationResource::class, 'POST', '/mining-operations/'.$id.'/depart', ['id']];
        yield 'GET ledger' => [MiningOperationLedgerResource::class, 'GET', '/mining-operations/'.$operationId.'/ledger', ['operationId']];
        yield 'POST snapshot retry' => [MiningOperationCharacterResource::class, 'POST', '/mining-operations/'.$operationId.'/characters/'.$id.'/snapshot-retry', ['operationId', 'id']];
        yield 'POST quantity correction' => [MiningOperationCharacterResource::class, 'POST', '/mining-operations/'.$operationId.'/characters/'.$id.'/quantity-corrections', ['operationId', 'id']];
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
