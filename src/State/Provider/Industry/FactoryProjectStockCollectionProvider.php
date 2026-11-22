<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\FactoryProjectStockResource;
use App\Entity\User;
use App\Service\Industry\Factory\FactoryProjectStock;
use App\Service\Industry\Factory\FactoryProjectStockLine;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<FactoryProjectStockResource>
 */
final readonly class FactoryProjectStockCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private FactoryProjectStock $factoryProjectStock,
    ) {
    }

    /**
     * @return list<FactoryProjectStockResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(static function (FactoryProjectStockLine $line): FactoryProjectStockResource {
            $resource = new FactoryProjectStockResource();
            $resource->id = \sprintf('%s:%d:%s', $line->projectId, $line->typeId, $line->state);
            $resource->projectId = $line->projectId;
            $resource->projectName = $line->projectName;
            $resource->projectStatus = $line->projectStatus;
            $resource->typeId = $line->typeId;
            $resource->typeName = $line->typeName;
            $resource->state = $line->state;
            $resource->quantity = $line->quantity;

            return $resource;
        }, $this->factoryProjectStock->of($user));
    }
}
