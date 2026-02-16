<?php

declare(strict_types=1);

namespace App\State\Provider\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProfitTracker\ProfitSettingsResource;
use App\Entity\User;
use App\Repository\ProfitSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProfitSettingsResource>
 */
class ProfitSettingsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ProfitSettingsRepository $settingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProfitSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->getOrCreate($user);

        $resource = new ProfitSettingsResource();
        $resource->salesTaxRate = $settings->getSalesTaxRate();
        $resource->defaultCostSource = $settings->getDefaultCostSource();
        $resource->updatedAt = $settings->getUpdatedAt()->format('c');

        return $resource;
    }
}
