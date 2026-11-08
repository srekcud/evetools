<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\MiningSite\MiningSiteRequest;
use App\Service\MiningSite\MiningSiteScanHider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<MiningSiteScanResource, null>
 */
final readonly class HideMiningSiteScanProcessor implements ProcessorInterface
{
    private const string SITE_SCAN_HIDDEN_EVENT = 'site_scan_hidden';

    public function __construct(
        private Security $security,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteScanHider $hider,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = MiningOperationRequest::userOf($this->security);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $this->access, $user, $uriVariables['siteId']);
        $scan = MiningSiteRequest::scanOf($this->scanRepository, $site, $uriVariables['id']);
        $this->hider->hide($user, $scan);

        $operationOfSite = $site->getOperation();
        if ($operationOfSite !== null) {
            $this->mercurePublisher->publishMiningOperationEvent(
                MiningOperationAudience::participantIdsOf($operationOfSite),
                PersistedUuid::of($operationOfSite->getId()),
                self::SITE_SCAN_HIDDEN_EVENT,
                [
                    'siteId' => PersistedUuid::of($site->getId()),
                    'scanId' => PersistedUuid::of($scan->getId()),
                ],
            );
        }

        return null;
    }
}
