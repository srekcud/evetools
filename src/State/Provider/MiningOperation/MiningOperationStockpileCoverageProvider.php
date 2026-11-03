<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationStockpileCoverageResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mining\OreStockpileCoverageService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationStockpileCoverageResource>
 */
final readonly class MiningOperationStockpileCoverageProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private OreStockpileCoverageService $coverageService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationStockpileCoverageResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);

        $member = $miningOperation->membershipOf($user) ?? throw new \LogicException('A participant has a membership');
        $coverage = $this->coverageService->coverageOf($user, $member);

        $resource = new MiningOperationStockpileCoverageResource();
        $resource->operationId = $coverage['operationId'];
        $resource->state = $coverage['state'];
        $resource->unknownReason = $coverage['unknownReason'];
        $resource->yieldKnown = $coverage['yieldKnown'];
        $resource->reprocessYield = $coverage['reprocessYield'];
        $resource->minerals = $coverage['minerals'];

        return $resource;
    }
}
