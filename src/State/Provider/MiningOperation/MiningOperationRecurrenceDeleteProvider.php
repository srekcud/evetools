<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationRecurrenceResource;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationRecurrenceResource>
 */
final readonly class MiningOperationRecurrenceDeleteProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRecurrenceRepository $recurrenceRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationRecurrenceResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $recurrence = MiningOperationRequest::activeRecurrenceOf($this->recurrenceRepository, $user, $uriVariables['id']);

        $resource = new MiningOperationRecurrenceResource();
        $resource->id = PersistedUuid::of($recurrence->getId());

        return $resource;
    }
}
