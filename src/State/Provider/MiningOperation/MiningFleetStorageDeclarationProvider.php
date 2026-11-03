<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProviderInterface<MiningFleetStorageResource>
 */
final readonly class MiningFleetStorageDeclarationProvider implements ProviderInterface
{
    private const string NOT_ALLOWED_TO_DECLARE_MESSAGE = 'Only the participant, the operation owner or an admin can declare this fleet storage';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningOperationAccess $access,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningFleetStorageResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $member = MiningOperationRequest::memberOf($this->memberRepository, $miningOperation, $uriVariables['memberId']);

        $this->access->assertCanParticipate($user, $miningOperation);
        $canAdminister = $this->access->canAdminister($user, $miningOperation);
        if (!$canAdminister && $member->getUser() !== $user) {
            throw new AccessDeniedHttpException(self::NOT_ALLOWED_TO_DECLARE_MESSAGE);
        }

        return $this->mapper->fleetStorageToResource(
            $miningOperation,
            $user,
            $canAdminister,
            $this->typeNameResolver->findNames($this->mapper->fleetStorageTypeIdsOf($miningOperation)),
        );
    }
}
