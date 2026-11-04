<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\MoonTaxSyncFailure;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Repository\MoonTaxSyncStateRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MoonTaxCorporationResource>
 */
final readonly class MoonTaxCorporationProvider implements ProviderInterface
{
    private const string NEVER_SYNCED = 'never_synced';

    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private MoonTaxRefinerySettingRepository $settingRepository,
        private MoonTaxSyncStateRepository $syncStateRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MoonTaxCorporationResource
    {
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);

        $state = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId]);

        $resource = new MoonTaxCorporationResource();
        $resource->corporationId = $corporationId;
        $resource->refineries = array_map(
            static fn (MoonTaxRefinerySetting $setting): array => [
                'structureId' => $setting->getStructureId(),
                'openToAlliance' => $setting->isOpenToAlliance(),
                'period' => $setting->getPeriod()->value,
                'rateR4' => $setting->getRateR4(),
                'rateR8' => $setting->getRateR8(),
                'rateR16' => $setting->getRateR16(),
                'rateR32' => $setting->getRateR32(),
                'rateR64' => $setting->getRateR64(),
                'walletDivision' => $setting->getWalletDivision(),
            ],
            $this->settingRepository->findBy(['corporationId' => $corporationId], ['structureId' => 'ASC']),
        );
        $resource->access = [
            'ledger' => $state === null ? self::NEVER_SYNCED : $this->accessOf($state->getObserversSyncedAt(), $state->getObserversFailure()),
            'payments' => $state === null ? self::NEVER_SYNCED : $this->accessOf($state->getWalletSyncedAt(), $state->getWalletFailure()),
        ];
        $resource->observersSyncedAt = $state?->getObserversSyncedAt();
        $resource->walletSyncedAt = $state?->getWalletSyncedAt();

        return $resource;
    }

    private function accessOf(?\DateTimeImmutable $syncedAt, ?MoonTaxSyncFailure $failure): string
    {
        return match ($failure) {
            MoonTaxSyncFailure::NoAccountantToken => 'denied',
            MoonTaxSyncFailure::ReconnectRequired => 'reconnect',
            MoonTaxSyncFailure::EsiError => 'error',
            null => $syncedAt === null ? self::NEVER_SYNCED : 'ok',
        };
    }
}
