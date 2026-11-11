<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\CorporationRoleAccessResource;
use App\Service\ESI\CorporationRoleAccessInspector;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<CorporationRoleAccessResource>
 */
final readonly class CorporationRoleAccessProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private CorporationRoleAccessInspector $inspector,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationRoleAccessResource
    {
        $feature = CorporationRoleAccessResource::featureOf($context['request'] ?? null);
        $corporationId = (int) $uriVariables['corporationId'];

        $state = $this->inspector->inspect(MiningOperationRequest::userOf($this->security), $corporationId, $feature);

        return CorporationRoleAccessResource::of($corporationId, $state->label(), $state->requiredRole, $state->characters);
    }
}
