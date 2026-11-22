<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\PersonalStockContainerResource;
use App\Entity\User;
use App\Service\Industry\Stock\PersonalStockReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PersonalStockContainerResource>
 */
final readonly class PersonalStockContainerItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PersonalStockReader $personalStockReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PersonalStockContainerResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $requestedId = (string) ($uriVariables['id'] ?? '');
        foreach ($this->personalStockReader->containersOf($user) as $reading) {
            if ($reading->id === $requestedId) {
                return PersonalStockContainerResourceMapper::of($reading);
            }
        }

        throw new NotFoundHttpException('Personal stock container not found');
    }
}
