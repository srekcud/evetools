<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ManufacturableTypeResource;
use App\Service\Industry\ManufacturableProductReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<ManufacturableTypeResource>
 */
class ManufacturableTypeProvider implements ProviderInterface
{
    private const string POSITIVE_DECIMAL_INTEGER = '/^[1-9][0-9]*$/';

    public function __construct(
        private readonly ManufacturableProductReader $manufacturableProductReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ManufacturableTypeResource
    {
        $typeId = $this->positiveTypeId($this->requestedTypeId($uriVariables, $context));
        $product = $this->manufacturableProductReader->findByTypeId($typeId);

        if (null === $product) {
            throw $this->manufacturableTypeNotFound();
        }

        $resource = new ManufacturableTypeResource();
        $resource->typeId = $product->typeId;
        $resource->typeName = $product->typeName;
        $resource->isT2 = $product->isT2;

        return $resource;
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    private function requestedTypeId(array $uriVariables, array $context): int|string
    {
        $request = $context['request'] ?? null;

        return $request instanceof Request ? $request->attributes->getString('typeId') : $uriVariables['typeId'];
    }

    private function positiveTypeId(int|string $requested): int
    {
        $text = (string) $requested;

        if (1 !== preg_match(self::POSITIVE_DECIMAL_INTEGER, $text)) {
            throw $this->manufacturableTypeNotFound();
        }

        $typeId = filter_var($text, FILTER_VALIDATE_INT);

        if (false === $typeId) {
            throw $this->manufacturableTypeNotFound();
        }

        return $typeId;
    }

    private function manufacturableTypeNotFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Manufacturable type not found');
    }
}
