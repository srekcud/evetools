<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationMinersEstimateResource;
use App\Repository\MiningOperationRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteMinersEstimateReader;
use App\Service\MiningSite\SiteLifespanSample;
use App\Service\MiningSite\TypeMinersEstimate;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationMinersEstimateResource>
 */
final readonly class MiningOperationMinersEstimateProvider implements ProviderInterface
{
    private const string NO_MINER_REASON = 'no_miner';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningSiteMinersEstimateReader $reader,
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationMinersEstimateResource
    {
        $user = MiningOperationRequest::userOf($this->security);
        $miningOperation = MiningOperationRequest::participatedOperationOf($this->operationRepository, $this->access, $user, (string) $uriVariables['operationId']);
        $estimates = $this->reader->read($user, $miningOperation->getPlannedStartAt());
        $typeNames = $this->invTypeRepository->findByTypeIds(array_map(static fn (TypeMinersEstimate $estimate): int => $estimate->typeId, $estimates));

        $resource = new MiningOperationMinersEstimateResource();
        $resource->operationId = (string) $miningOperation->getId();
        $resource->startAt = $miningOperation->getPlannedStartAt();
        $resource->types = array_map(fn (TypeMinersEstimate $estimate): array => $this->typeToArray($estimate, ($typeNames[$estimate->typeId] ?? null)?->getTypeName()), $estimates);

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeToArray(TypeMinersEstimate $estimate, ?string $typeName): array
    {
        $needed = $estimate->needed;

        return [
            'typeId' => $estimate->typeId,
            'typeName' => $typeName,
            'status' => $needed->status->value,
            'knownSiteCount' => $needed->knownSiteCount,
            'workMinerMinutes' => $needed->workMinerMinutes,
            'minersNeeded' => $needed->minersNeeded,
            'deadline' => $needed->deadline?->format(\DateTimeInterface::ATOM),
            'availableMinutes' => $needed->availableMinutes,
            'durations' => $needed->durations,
            'sites' => array_map($this->siteToArray(...), $estimate->sites),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteToArray(SiteLifespanSample $site): array
    {
        $workload = $site->workMinerMinutes();

        return [
            'label' => $site->label,
            'firstScanAt' => $site->firstScanAt->format(\DateTimeInterface::ATOM),
            'lifespanMinutes' => $site->lifespanMinutes,
            'miners' => $site->miners,
            'workMinerMinutes' => $workload,
            'unknownReason' => $workload === null ? self::NO_MINER_REASON : null,
        ];
    }
}
