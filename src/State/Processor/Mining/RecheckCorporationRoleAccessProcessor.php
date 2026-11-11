<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\CorporationRoleAccessResource;
use App\Service\ESI\CorporationRoleAccessInspector;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, CorporationRoleAccessResource>
 */
final readonly class RecheckCorporationRoleAccessProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private CorporationRoleAccessInspector $inspector,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CorporationRoleAccessResource
    {
        $feature = CorporationRoleAccessResource::featureOf($context['request'] ?? null);
        $corporationId = (int) $uriVariables['corporationId'];

        $state = $this->inspector->recheck(MiningOperationRequest::userOf($this->security), $corporationId, $feature);

        return CorporationRoleAccessResource::of($corporationId, $state->label(), $state->requiredRole, $state->characters);
    }
}
