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
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;
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
        private readonly array $adminCharacterNames,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ActionResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $operationName = $operation->getName();
        $resource = new ActionResultResource();
        $resource->success = true;

        switch ($operationName) {
            case '_api_/admin/actions/sync-assets_post':
            case 'sync_assets':
                $this->messageBus->dispatch(new TriggerAssetsSync());
                $resource->message = 'Assets sync triggered';
                break;

            case '_api_/admin/actions/sync-market_post':
            case 'sync_market':
                $this->messageBus->dispatch(new TriggerJitaMarketSync());
                $this->messageBus->dispatch(new TriggerStructureMarketSync());
                $resource->message = 'Market sync triggered (Jita + Structure)';
                break;

            case '_api_/admin/actions/sync-pve_post':
            case 'sync_pve':
                $this->messageBus->dispatch(new TriggerPveSync());
                $resource->message = 'PVE sync triggered';
                break;

            case '_api_/admin/actions/sync-industry_post':
            case 'sync_industry':
                $this->messageBus->dispatch(new SyncIndustryJobs());
                $resource->message = 'Industry jobs sync triggered';
                break;

            case '_api_/admin/actions/sync-ansiblex_post':
            case 'sync_ansiblex':
                $this->messageBus->dispatch(new TriggerAnsiblexSync());
                $resource->message = 'Ansiblex sync triggered';
                break;

            default:
                $resource->success = false;
                $resource->message = 'Unknown sync action';
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
