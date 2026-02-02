<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\SettingsResource;
use App\ApiResource\Pve\TypeResource;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<SettingsResource>
 */
class SettingsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->getOrCreate($user);

        $ammoTypes = [];
        foreach ($settings->getAmmoTypeIds() as $typeId) {
            $type = $this->invTypeRepository->find($typeId);
            $typeResource = new TypeResource();
            $typeResource->typeId = $typeId;
            $typeResource->typeName = $type?->getTypeName() ?? "Type #{$typeId}";
            $ammoTypes[] = $typeResource;
        }

        $lootTypes = [];
        foreach ($settings->getLootTypeIds() as $typeId) {
            $type = $this->invTypeRepository->find($typeId);
            $typeResource = new TypeResource();
            $typeResource->typeId = $typeId;
            $typeResource->typeName = $type?->getTypeName() ?? "Type #{$typeId}";
            $lootTypes[] = $typeResource;
        }

        $resource = new SettingsResource();
        $resource->ammoTypes = $ammoTypes;
        $resource->lootTypes = $lootTypes;
        $resource->declinedContractsCount = count($settings->getDeclinedContractIds());
        $resource->declinedTransactionsCount = count($settings->getDeclinedLootSaleTransactionIds());

        return $resource;
    }
}
