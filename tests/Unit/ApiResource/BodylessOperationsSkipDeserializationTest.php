<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use App\ApiResource\Assets\AssetItemNameResource;
use App\ApiResource\Industry\Project\ProjectParticipantResource;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningHaulerPlanResource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-862')]
final class BodylessOperationsSkipDeserializationTest extends TestCase
{
    private const string API_RESOURCE_DIRECTORY = __DIR__.'/../../../src/ApiResource';
    private const string API_RESOURCE_NAMESPACE = 'App\\ApiResource\\';
    private const array BODY_CARRYING_METHODS = ['POST', 'PUT', 'PATCH'];

    public function testEveryBodylessMutatingOperationSkipsDeserialization(): void
    {
        $offendingOperations = [];
        foreach ($this->bodylessMutatingOperations() as $label => $operation) {
            if (false !== $operation->canDeserialize()) {
                $offendingOperations[] = $label;
            }
        }

        self::assertSame([], $offendingOperations);
    }

    public function testTheScanReadsTheBodylessOperationsAlreadyFixedAndTheOnesReportedInIssue862(): void
    {
        $labels = array_keys($this->bodylessMutatingOperations());

        self::assertNotEmpty($this->labelsOf(AssetItemNameResource::class, $labels));
        self::assertNotEmpty($this->labelsOf(MiningHaulerPlanResource::class, $labels));
        self::assertNotEmpty($this->labelsOf(ProjectParticipantResource::class, $labels));
    }

    public function testAnOperationWithARealInputDtoStillDeserializesItsBody(): void
    {
        $bodyCarryingOperations = array_filter(
            $this->allOperations(),
            fn (HttpOperation $operation): bool => \in_array($operation->getMethod(), self::BODY_CARRYING_METHODS, true)
                && !$this->isBodyless($operation),
        );

        self::assertNotEmpty($bodyCarryingOperations);
        foreach ($bodyCarryingOperations as $label => $operation) {
            self::assertNotFalse($operation->canDeserialize(), $label);
        }
    }

    /**
     * @return array<string, HttpOperation>
     */
    private function bodylessMutatingOperations(): array
    {
        return array_filter(
            $this->allOperations(),
            fn (HttpOperation $operation): bool => \in_array($operation->getMethod(), self::BODY_CARRYING_METHODS, true)
                && $this->isBodyless($operation),
        );
    }

    private function isBodyless(HttpOperation $operation): bool
    {
        $input = $operation->getInput();

        return EmptyInput::class === (\is_array($input) ? ($input['class'] ?? null) : $input);
    }

    /**
     * @return array<string, HttpOperation>
     */
    private function allOperations(): array
    {
        $operations = [];
        foreach ($this->apiResourceClasses() as $resourceClass) {
            foreach ((new \ReflectionClass($resourceClass))->getAttributes(ApiResource::class) as $apiResourceAttribute) {
                foreach ($apiResourceAttribute->newInstance()->getOperations() ?? [] as $operation) {
                    if ($operation instanceof HttpOperation) {
                        $operations[$resourceClass.' '.$operation->getMethod().' '.$operation->getUriTemplate()] = $operation;
                    }
                }
            }
        }

        return $operations;
    }

    /**
     * @return list<class-string>
     */
    private function apiResourceClasses(): array
    {
        $classes = [];
        $directory = (string) realpath(self::API_RESOURCE_DIRECTORY);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -\strlen('.php'));
            $class = self::API_RESOURCE_NAMESPACE.str_replace('/', '\\', $relativePath);
            if (class_exists($class) && [] !== (new \ReflectionClass($class))->getAttributes(ApiResource::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function labelsOf(string $resourceClass, array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => str_starts_with($label, $resourceClass.' ')));
    }
}
