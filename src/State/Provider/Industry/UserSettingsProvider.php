<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\UserSettingsResource;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<UserSettingsResource>
 */
class UserSettingsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        $resource = new UserSettingsResource();

        if ($settings !== null) {
            $resource->favoriteManufacturingSystemId = $settings->getFavoriteManufacturingSystemId();
            $resource->favoriteReactionSystemId = $settings->getFavoriteReactionSystemId();

            if ($resource->favoriteManufacturingSystemId !== null) {
                $system = $this->solarSystemRepository->find($resource->favoriteManufacturingSystemId);
                $resource->favoriteManufacturingSystemName = $system?->getSolarSystemName();
            }

            if ($resource->favoriteReactionSystemId !== null) {
                $system = $this->solarSystemRepository->find($resource->favoriteReactionSystemId);
                $resource->favoriteReactionSystemName = $system?->getSolarSystemName();
            }
        }

        return $resource;
    }
}
