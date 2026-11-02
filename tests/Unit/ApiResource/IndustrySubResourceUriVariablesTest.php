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
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Industry\StepPurchaseResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-233')]
final class IndustrySubResourceUriVariablesTest extends TestCase
{
    private const string INDUSTRY_API_RESOURCE_NAMESPACE = 'App\\ApiResource\\Industry\\';

    #[DataProvider('industrySubResourceOperations')]
    public function testIndustrySubResourceOperationDeclaresEveryUriVariableAsLink(string $resourceShortName, HttpOperation $operation): void
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

    public static function industrySubResourceOperations(): iterable
    {
        $detector = new SubResourceUriVariablesDetector();

        foreach (glob(\dirname(__DIR__, 3).'/src/ApiResource/Industry/*.php') ?: [] as $resourceFile) {
            $resourceClass = new \ReflectionClass(self::INDUSTRY_API_RESOURCE_NAMESPACE.basename($resourceFile, '.php'));

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

    public function testStepPatchWithoutUriVariablesLeavesProjectAndStepUndeclared(): void
    {
        $operation = new Patch(uriTemplate: '/industry/projects/{id}/steps/{stepId}');

        $this->assertSame(['id', 'stepId'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testStepPatchDeclaringProjectAndStepLinksHasNoUndeclaredUriVariable(): void
    {
        $operation = new Patch(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}',
            uriVariables: [
                'id' => new Link(fromClass: ProjectResource::class),
                'stepId' => new Link(fromClass: ProjectStepResource::class),
            ],
        );

        $this->assertSame([], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testStepPatchDeclaringOnlyProjectLinkLeavesStepUndeclared(): void
    {
        $operation = new Patch(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}',
            uriVariables: ['id' => new Link(fromClass: ProjectResource::class)],
        );

        $this->assertSame(['stepId'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testStepPostWithoutUriVariablesLeavesProjectUndeclared(): void
    {
        $operation = new Post(uriTemplate: '/industry/projects/{id}/steps');

        $this->assertSame(['id'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testPurchaseDeleteDeclaringOnlyParentLinksLeavesFinalPurchaseUndeclared(): void
    {
        $operation = new Delete(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/purchases/{purchaseId}',
            uriVariables: [
                'id' => new Link(fromClass: ProjectResource::class),
                'stepId' => new Link(fromClass: ProjectStepResource::class),
            ],
        );

        $this->assertSame(['purchaseId'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testPurchaseDeleteDeclaringAllLinksHasNoUndeclaredUriVariable(): void
    {
        $operation = new Delete(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}/purchases/{purchaseId}',
            uriVariables: [
                'id' => new Link(fromClass: ProjectResource::class),
                'stepId' => new Link(fromClass: ProjectStepResource::class),
                'purchaseId' => new Link(fromClass: StepPurchaseResource::class),
            ],
        );

        $this->assertSame([], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
    }

    public function testUriVariableDeclaredAsPlainStringIsUndeclared(): void
    {
        $operation = new Patch(
            uriTemplate: '/industry/projects/{id}/steps/{stepId}',
            uriVariables: ['id' => 'id', 'stepId' => new Link(fromClass: ProjectStepResource::class)],
        );

        $this->assertSame(['id'], (new SubResourceUriVariablesDetector())->undeclaredUriVariables($operation));
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
}
