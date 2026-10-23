<?php

declare(strict_types=1);

namespace App\Tests\Integration\ApiResource\Industry;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\State\Provider\Industry\ProjectProvider;
use App\State\Provider\Industry\ProjectReferenceProvider;
use App\State\Provider\Industry\ProjectStepReferenceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('issue-204')]
final class ProjectReferenceProviderOperationsTest extends KernelTestCase
{
    private const string PROJECT_URI_TEMPLATE = '/industry/projects/{id}';
    private const string STEP_URI_TEMPLATE = '/industry/projects/{id}/steps/{stepId}';

    public static function operationsProvidingOnlyTheIdentifier(): iterable
    {
        yield 'project PATCH' => [ProjectResource::class, 'PATCH', self::PROJECT_URI_TEMPLATE, ProjectReferenceProvider::class];
        yield 'project DELETE' => [ProjectResource::class, 'DELETE', self::PROJECT_URI_TEMPLATE, ProjectReferenceProvider::class];
        yield 'step PATCH' => [ProjectStepResource::class, 'PATCH', self::STEP_URI_TEMPLATE, ProjectStepReferenceProvider::class];
        yield 'step DELETE' => [ProjectStepResource::class, 'DELETE', self::STEP_URI_TEMPLATE, ProjectStepReferenceProvider::class];
    }

    #[DataProvider('operationsProvidingOnlyTheIdentifier')]
    public function testOperationUsesTheReferenceProvider(string $resourceClass, string $method, string $uriTemplate, string $referenceProvider): void
    {
        self::assertSame($referenceProvider, $this->providerOf($resourceClass, $method, $uriTemplate));
    }

    public function testProjectGetKeepsTheProviderBuildingTheProductionTree(): void
    {
        self::assertSame(
            ProjectProvider::class,
            $this->providerOf(ProjectResource::class, 'GET', self::PROJECT_URI_TEMPLATE),
        );
    }

    private function providerOf(string $resourceClass, string $method, string $uriTemplate): mixed
    {
        $metadataFactory = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        foreach ($metadataFactory->create($resourceClass) as $resourceMetadata) {
            foreach ($resourceMetadata->getOperations() ?? [] as $operation) {
                if ($operation->getMethod() === $method && $operation->getUriTemplate() === $uriTemplate) {
                    return $operation->getProvider();
                }
            }
        }

        self::fail(sprintf('No %s %s operation on %s', $method, $uriTemplate, $resourceClass));
    }
}
