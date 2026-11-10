<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationArchive;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationResource>
 */
final readonly class ArchivedMiningOperationsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationArchive $archive,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningOperationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        return array_map(
            function (MiningOperation $archived) use ($user): MiningOperationResource {
                $resource = $this->mapper->operationToResource($archived, $user, $archived->activeMembershipOf($user));
                $resource->archiveSummary = $this->archive->summaryOf($archived, $user);

                return $resource;
            },
            $this->operationRepository->findArchivedReadableBy($user),
        );
    }
}
