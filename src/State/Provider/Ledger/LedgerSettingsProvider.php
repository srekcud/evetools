<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\LedgerSettingsResource;
use App\Entity\User;
use App\Repository\UserLedgerSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LedgerSettingsResource>
 */
class LedgerSettingsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserLedgerSettingsRepository $settingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LedgerSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->getOrCreate($user);

        $resource = new LedgerSettingsResource();
        $resource->corpProjectAccounting = $settings->getCorpProjectAccounting();
        $resource->autoSyncEnabled = $settings->isAutoSyncEnabled();
        $resource->lastMiningSyncAt = $settings->getLastMiningSyncAt()?->format('c');
        $resource->excludedTypeIds = $settings->getExcludedTypeIds();
        $resource->defaultSoldTypeIds = $settings->getDefaultSoldTypeIds();
        $resource->updatedAt = $settings->getUpdatedAt()->format('c');

        return $resource;
    }
}
