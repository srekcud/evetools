<?php

declare(strict_types=1);

namespace App\State\Processor\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Ledger\LedgerSettingsResource;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\UserLedgerSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<LedgerSettingsResource, LedgerSettingsResource>
 */
class UpdateLedgerSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserLedgerSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LedgerSettingsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $settings = $this->settingsRepository->getOrCreate($user);

        assert($data instanceof LedgerSettingsResource);

        // Update corpProjectAccounting if provided
        if (isset($data->corpProjectAccounting)) {
            if (!in_array($data->corpProjectAccounting, [
                UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE,
                UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING,
            ], true)) {
                throw new BadRequestHttpException(sprintf(
                    'Invalid corpProjectAccounting value. Must be "%s" or "%s".',
                    UserLedgerSettings::CORP_PROJECT_ACCOUNTING_PVE,
                    UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING
                ));
            }
            $settings->setCorpProjectAccounting($data->corpProjectAccounting);
        }

        // Update autoSyncEnabled if provided
        if (isset($data->autoSyncEnabled)) {
            $settings->setAutoSyncEnabled($data->autoSyncEnabled);
        }

        // Update excludedTypeIds if provided
        if (isset($data->excludedTypeIds)) {
            $settings->setExcludedTypeIds($data->excludedTypeIds);
        }

        // Update defaultSoldTypeIds if provided
        if (isset($data->defaultSoldTypeIds)) {
            $settings->setDefaultSoldTypeIds($data->defaultSoldTypeIds);
        }

        $this->entityManager->flush();

        // Return updated settings
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
