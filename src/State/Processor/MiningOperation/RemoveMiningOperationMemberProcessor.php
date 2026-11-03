<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @implements ProcessorInterface<null, null>
 */
final readonly class RemoveMiningOperationMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningOperationAccess $access,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanAdminister($user, $miningOperation);

        $member = MiningOperationRequest::memberOf($this->memberRepository, $miningOperation, $uriVariables['id']);

        if ($member->getRole() === MiningOperationMemberRole::Owner) {
            throw new BadRequestHttpException('Cannot remove the operation owner');
        }

        $this->access->assertCanManageMember($user, $miningOperation, $member);

        if ($member->getStatus() === MiningOperationMemberStatus::Removed) {
            throw new BadRequestHttpException('Member already removed');
        }

        if ($member->getStatus() === MiningOperationMemberStatus::Accepted && $miningOperation->getStatus() !== MiningOperationStatus::Planned) {
            throw new ConflictHttpException('Mining operation already started: use departure instead');
        }

        $member->remove();
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation, $member->getUser()),
            PersistedUuid::of($miningOperation->getId()),
            'member_removed',
            ['memberId' => PersistedUuid::of($member->getId())],
        );

        return null;
    }
}
