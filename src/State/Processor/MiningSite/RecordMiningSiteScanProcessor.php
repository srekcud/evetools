<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningSite\RecordMiningSiteScanInput;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Entity\MiningSite;
use App\Repository\MiningSiteRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteRequest;
use App\Service\MiningSite\MiningSiteScanRecorder;
use App\Service\MiningSite\ScanLine;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<RecordMiningSiteScanInput, MiningSiteScanResource>
 */
final readonly class RecordMiningSiteScanProcessor implements ProcessorInterface
{
    private const string SITE_SCAN_RECORDED_EVENT = 'site_scan_recorded';

    public function __construct(
        private Security $security,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRecorder $recorder,
        private MiningSiteResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteScanResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof RecordMiningSiteScanInput);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $this->access, $user, $uriVariables['siteId']);
        $lines = array_map(
            static fn (array $line): ScanLine => new ScanLine($line['typeId'], $line['units']),
            $data->lines,
        );

        $scan = $this->recorder->record($site, $lines, $user);
        $resource = $this->mapper->scanToResource($scan);
        $this->publishToTheOperation($site, $resource);

        return $resource;
    }

    private function publishToTheOperation(MiningSite $site, MiningSiteScanResource $scan): void
    {
        $operation = $site->getOperation();
        if ($operation === null) {
            return;
        }

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($operation),
            PersistedUuid::of($operation->getId()),
            self::SITE_SCAN_RECORDED_EVENT,
            [
                'siteId' => PersistedUuid::of($site->getId()),
                'scanId' => $scan->id,
                'number' => $scan->number,
                'authorName' => $scan->authorName,
                'scannedAt' => $scan->scannedAt?->format(\DateTimeInterface::ATOM),
                'recentEta' => $scan->recentEta?->format(\DateTimeInterface::ATOM),
                'trendEta' => $scan->trendEta?->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
