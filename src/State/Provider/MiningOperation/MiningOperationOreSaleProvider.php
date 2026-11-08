<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreSaleResource;
use App\Enum\MinedQuantityState;
use App\Repository\MiningOperationRepository;
use App\Service\Mining\OreSaleService;
use App\Service\MiningOperation\MinedOreCompressedQuantities;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\Mining\OreSaleStructureId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @implements ProviderInterface<OreSaleResource>
 */
final readonly class MiningOperationOreSaleProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private MinedOreCompressedQuantities $compressedQuantities,
        private OreSaleService $oreSaleService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreSaleResource
    {
        $user = MiningOperationRequest::userOf($this->security);
        $request = $this->requestStack->getCurrentRequest() ?? throw new BadRequestHttpException('No current request.');
        $structureId = OreSaleStructureId::of($request->query);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $this->access->assertCanParticipate($user, $miningOperation);
        $membership = $miningOperation->membershipOf($user) ?? throw new \LogicException('A participant has a membership');

        $minedQuantity = $this->calculator->ofParticipant($membership);
        if ($minedQuantity->state !== MinedQuantityState::Known) {
            throw new ConflictHttpException('The mined quantities of this operation are not known yet');
        }

        $compressed = $this->compressedQuantities->of($minedQuantity->quantitiesByTypeId);
        $sale = $this->oreSaleService->buildFromQuantities($compressed['quantities'], $structureId);

        $resource = new OreSaleResource();
        $resource->operationId = PersistedUuid::of($miningOperation->getId());
        $resource->structureId = $structureId;
        $resource->rows = $sale['rows'];
        $resource->totals = $sale['totals'];
        $resource->unconvertedTypes = $compressed['unconvertedTypes'];

        return $resource;
    }
}
