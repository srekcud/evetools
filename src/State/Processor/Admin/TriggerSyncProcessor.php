<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Admin\ActionResultResource;
use App\Entity\User;
use App\Message\SyncIndustryJobs;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerAssetsSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningSync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;
use App\Message\SyncWalletTransactions;
use App\Service\Admin\SyncTracker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, ActionResultResource>
 */
class TriggerSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $messageBus,
        private readonly SyncTracker $syncTracker,
        /** @var list<string> */
        private readonly array $adminCharacterNames,
    ) {
    }

    private const ACTION_TO_SYNC_TYPE = [
        'sync_assets' => 'assets',
        'sync_market' => 'market-jita',
        'sync_pve' => 'pve',
        'sync_industry' => 'industry',
        'sync_wallet' => 'wallet',
        'sync_mining' => 'mining',
        'sync_ansiblex' => 'ansiblex',
        'sync_planetary' => 'planetary',
    ];

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ActionResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $operationName = $operation->getName();
        $userId = $user->getId()?->toRfc4122();
        $resource = new ActionResultResource();
        $resource->success = true;

        // Resolve short operation name (strip API Platform prefix)
        $shortName = $operationName ?? '';
        foreach (self::ACTION_TO_SYNC_TYPE as $action => $syncType) {
            if (str_contains($shortName, str_replace('_', '-', $action))) {
                $shortName = $action;
                break;
            }
        }

        switch ($shortName) {
            case 'sync_assets':
                $this->messageBus->dispatch(new TriggerAssetsSync());
                $resource->message = 'Assets sync triggered';
                break;

            case 'sync_market':
                $this->messageBus->dispatch(new TriggerJitaMarketSync());
                $this->messageBus->dispatch(new TriggerStructureMarketSync());
                $resource->message = 'Market sync triggered (Jita + Structure)';
                break;

            case 'sync_pve':
                $this->messageBus->dispatch(new TriggerPveSync());
                $resource->message = 'PVE sync triggered';
                break;

            case 'sync_industry':
                $this->messageBus->dispatch(new SyncIndustryJobs());
                $resource->message = 'Industry jobs sync triggered';
                break;

            case 'sync_wallet':
                $this->messageBus->dispatch(new SyncWalletTransactions());
                $resource->message = 'Wallet transactions sync triggered';
                break;

            case 'sync_mining':
                $this->messageBus->dispatch(new TriggerMiningSync());
                $resource->message = 'Mining ledger sync triggered';
                break;

            case 'sync_ansiblex':
                $this->messageBus->dispatch(new TriggerAnsiblexSync());
                $resource->message = 'Ansiblex sync triggered';
                break;

            case 'sync_planetary':
                $this->messageBus->dispatch(new TriggerPlanetarySync());
                $resource->message = 'Planetary Interaction sync triggered';
                break;

            default:
                $resource->success = false;
                $resource->message = 'Unknown sync action';
        }

        // Register admin user for Mercure notification on completion
        if ($resource->success && $userId !== null && isset(self::ACTION_TO_SYNC_TYPE[$shortName])) {
            $syncType = self::ACTION_TO_SYNC_TYPE[$shortName];
            $this->syncTracker->setTriggeredBy($syncType, $userId);
            // Market sync has two types
            if ($shortName === 'sync_market') {
                $this->syncTracker->setTriggeredBy('market-structure', $userId);
            }
        }

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        $mainChar = $user->getMainCharacter();
        if (!$mainChar) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $mainCharName = strtolower($mainChar->getName());
        $isAdmin = false;
        foreach ($this->adminCharacterNames as $adminName) {
            if (strtolower($adminName) === $mainCharName) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}
