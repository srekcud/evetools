<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PersonalStockContainerResource;
use App\Entity\User;
use App\Service\Industry\Stock\PersonalStockReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PersonalStockContainerResource>
 */
final readonly class PersonalStockContainerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PersonalStockReader $personalStockReader,
    ) {
    }

    /**
     * @return list<PersonalStockContainerResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(PersonalStockContainerResourceMapper::of(...), $this->personalStockReader->containersOf($user));
    }
}
