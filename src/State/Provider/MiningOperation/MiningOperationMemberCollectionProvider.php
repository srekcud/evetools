<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\Entity\MiningOperationMember;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationMemberResource>
 */
final readonly class MiningOperationMemberCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningOperationMemberResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);

        $listedMembers = array_values(array_filter(
            $miningOperation->getMembers()->toArray(),
            static fn (MiningOperationMember $member): bool => $member->getStatus() !== MiningOperationMemberStatus::Removed,
        ));
        usort($listedMembers, static fn (MiningOperationMember $left, MiningOperationMember $right): int => [$left->getRole() !== MiningOperationMemberRole::Owner, $left->getJoinedAt()] <=> [$right->getRole() !== MiningOperationMemberRole::Owner, $right->getJoinedAt()]);

        return array_map($this->mapper->memberToResource(...), $listedMembers);
    }
}
