<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningSite\RecordMiningSiteScanInput;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Repository\MiningSiteRepository;
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
    public function __construct(
        private Security $security,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRecorder $recorder,
        private MiningSiteResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteScanResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof RecordMiningSiteScanInput);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $user, $uriVariables['siteId']);
        $lines = array_map(
            static fn (array $line): ScanLine => new ScanLine($line['typeId'], $line['units']),
            $data->lines,
        );

        return $this->mapper->scanToResource($this->recorder->record($site, $lines));
    }
}
