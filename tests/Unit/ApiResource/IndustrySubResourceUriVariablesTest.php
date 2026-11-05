<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryBomResource;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\ApiResource\Industry\ProjectPurchaseResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-233')]
final class IndustrySubResourceUriVariablesTest extends TestCase
{
    private const string INDUSTRY_API_RESOURCE_NAMESPACE = 'App\\ApiResource\\Industry\\';
    private const string GROUP_INDUSTRY_API_RESOURCE_NAMESPACE = 'App\\ApiResource\\GroupIndustry\\';

    #[DataProvider('industrySubResourceOperations')]
    public function testIndustrySubResourceOperationDeclaresEveryUriVariableAsLink(string $resourceShortName, HttpOperation $operation): void
    {
        $this->assertEveryUriVariableIsDeclaredAsLink($resourceShortName, $operation);
    }

    #[DataProvider('groupIndustrySubResourceOperations')]
    #[Group('issue-206')]
    public function testGroupIndustrySubResourceOperationDeclaresEveryUriVariableAsLink(string $resourceShortName, HttpOperation $operation): void
    {
        $this->assertEveryUriVariableIsDeclaredAsLink($resourceShortName, $operation);
    }

    public static function industrySubResourceOperations(): iterable
    {
        return self::subResourceOperationsOf('Industry', self::INDUSTRY_API_RESOURCE_NAMESPACE);
    }

    public static function groupIndustrySubResourceOperations(): iterable
    {
        return self::subResourceOperationsOf('GroupIndustry', self::GROUP_INDUSTRY_API_RESOURCE_NAMESPACE);
    }

    private static function subResourceOperationsOf(string $apiResourceDirectory, string $apiResourceNamespace): iterable
    {
        $detector = new SubResourceUriVariablesDetector();

        foreach (glob(\dirname(__DIR__, 3).'/src/ApiResource/'.$apiResourceDirectory.'/*.php') ?: [] as $resourceFile) {
            $resourceClass = new \ReflectionClass($apiResourceNamespace.basename($resourceFile, '.php'));

            foreach ($resourceClass->getAttributes(ApiResource::class) as $apiResourceAttribute) {
                foreach ($apiResourceAttribute->newInstance()->getOperations() ?? [] as $operation) {
                    if ($operation instanceof HttpOperation && $detector->isSubResource($operation)) {
                        $caseName = \sprintf('%s %s %s', $resourceClass->getShortName(), $operation->getMethod(), $operation->getUriTemplate());

                        yield $caseName => [$resourceClass->getShortName(), $operation];
                    }
                }
            }
        }
    }

    private function assertEveryUriVariableIsDeclaredAsLink(string $resourceShortName, HttpOperation $operation): void
    {
        $undeclaredUriVariables = (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation);

        $this->assertSame(
            [],
            $undeclaredUriVariables,
            \sprintf(
                '%s %s: uriVariables declares no Link for {%s}',
                $resourceShortName,
                $operation->getUriTemplate(),
                implode('}, {', $undeclaredUriVariables),
            ),
        );
    }

    public function testPurchaseDeleteDeclaringOnlyParentLinksLeavesFinalPurchaseUndeclared(): void
    {
        $operation = new Delete(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/purchases/{purchaseId}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'lineId' => new Link(fromClass: GroupIndustryBomResource::class),
            ],
        );

        $this->assertSame(['purchaseId'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testPurchaseDeleteDeclaringAllLinksHasNoUndeclaredUriVariable(): void
    {
        $operation = new Delete(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}/purchases/{purchaseId}',
            uriVariables: [
                'projectId' => new Link(fromClass: GroupIndustryProjectResource::class),
                'lineId' => new Link(fromClass: GroupIndustryBomResource::class),
                'purchaseId' => new Link(fromClass: ProjectPurchaseResource::class),
            ],
        );

        $this->assertSame([], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testUriVariableDeclaredAsPlainStringIsUndeclared(): void
    {
        $operation = new Patch(
            uriTemplate: '/industry/projects/{projectId}/bom/{lineId}',
            uriVariables: ['projectId' => 'projectId', 'lineId' => new Link(fromClass: GroupIndustryBomResource::class)],
        );

        $this->assertSame(['projectId'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testProjectGetWithFinalIdentifierIsNotSubResource(): void
    {
        $operation = new Get(uriTemplate: '/industry/projects/{id}');
        $detector = new SubResourceUriVariablesDetector();

        $this->assertFalse($detector->isSubResource($operation));
        $this->assertSame([], $detector->undeclaredUriVariables($operation));
    }

    public function testStockpileTargetGetWithFinalRequirementVariableIsNotSubResource(): void
    {
        $operation = new Get(uriTemplate: '/industry/stockpile-targets/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}');
        $detector = new SubResourceUriVariablesDetector();

        $this->assertFalse($detector->isSubResource($operation));
        $this->assertSame([], $detector->undeclaredUriVariables($operation));
    }

    public function testRequirementVariableFollowedBySegmentIsSubResource(): void
    {
        $operation = new Post(uriTemplate: '/industry/projects/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}/steps');

        $this->assertSame(['id'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    #[Group('issue-206')]
    public function testGroupMemberPatchDeclaringOnlyProjectLinkLeavesFinalRequirementMemberUndeclared(): void
    {
        $operation = new Patch(
            uriTemplate: '/group-industry/projects/{projectId}/members/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: ['projectId' => new Link(fromClass: GroupIndustryProjectResource::class)],
        );

        $this->assertSame(['id'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }
}
