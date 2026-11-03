<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationMemberInput;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<UpdateMiningOperationMemberInput, MiningOperationMemberResource>
 */
final readonly class UpdateMiningOperationMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationMemberResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof UpdateMiningOperationMemberInput);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanAdminister($user, $miningOperation);

        $member = MiningOperationRequest::memberOf($this->memberRepository, $miningOperation, $uriVariables['id']);

        if ($member->getRole() === MiningOperationMemberRole::Owner) {
            throw new BadRequestHttpException('Cannot modify the operation owner');
        }

        $this->access->assertCanManageMember($user, $miningOperation, $member);

        $newRole = $data->role === null ? null : MiningOperationMemberRole::from($data->role);
        if ($newRole === MiningOperationMemberRole::Admin && $miningOperation->getOwner() !== $user) {
            throw new AccessDeniedHttpException('Only the operation owner can promote members to admin');
        }

        $accepts = $data->status === MiningOperationMemberStatus::Accepted->value;
        if ($accepts && $member->getStatus() !== MiningOperationMemberStatus::Pending) {
            throw new BadRequestHttpException('Only pending members can be accepted');
        }

        if ($newRole !== null) {
            $member->setRole($newRole);
        }
        if ($accepts) {
            $member->setStatus(MiningOperationMemberStatus::Accepted);
        }
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation, $member->getUser()),
            PersistedUuid::of($miningOperation->getId()),
            'member_updated',
            [
                'memberId' => PersistedUuid::of($member->getId()),
                'role' => $member->getRole()->value,
                'status' => $member->getStatus()->value,
            ],
        );

        return $this->mapper->memberToResource($member);
    }
}
