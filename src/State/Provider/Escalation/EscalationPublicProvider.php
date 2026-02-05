<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Escalation\EscalationResource;
use App\Repository\EscalationRepository;

/**
 * @implements ProviderInterface<EscalationResource>
 */
class EscalationPublicProvider implements ProviderInterface
{
    public function __construct(
        private readonly EscalationRepository $escalationRepository,
    ) {
    }

    /**
     * @return EscalationResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $entries = $this->escalationRepository->findPublic();

        return array_map(fn($e) => EscalationResourceMapper::toResource($e, false), $entries);
    }
}
