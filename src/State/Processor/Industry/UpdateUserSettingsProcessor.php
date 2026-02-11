<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\UserSettingsResource;
use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateUserSettingsInput, UserSettingsResource>
 */
class UpdateUserSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryUserSettingsRepository $settingsRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof UpdateUserSettingsInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        $settings = $this->settingsRepository->findOneBy(['user' => $user]);

        if ($settings === null) {
            $settings = new IndustryUserSettings();
            $settings->setUser($user);
            $this->entityManager->persist($settings);
        }

        if ($data->favoriteManufacturingSystemId !== null) {
            $settings->setFavoriteManufacturingSystemId(
                $data->favoriteManufacturingSystemId > 0 ? $data->favoriteManufacturingSystemId : null
            );
        }

        if ($data->favoriteReactionSystemId !== null) {
            $settings->setFavoriteReactionSystemId(
                $data->favoriteReactionSystemId > 0 ? $data->favoriteReactionSystemId : null
            );
        }

        $this->entityManager->flush();

        // Build response
        $resource = new UserSettingsResource();
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

        return $resource;
    }
}
