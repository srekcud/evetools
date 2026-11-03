<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\UpdateMiningFleetStorageInput;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningFleetStorageDeclarations;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\TypeNameResolver;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateMiningFleetStorageInput, MiningFleetStorageResource>
 */
final readonly class UpdateMiningFleetStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningFleetStorageDeclarations $declarations,
        private MiningOperationAccess $access,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningFleetStorageResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof UpdateMiningFleetStorageInput);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanAdminister($user, $miningOperation);
        $this->declarations->assertEditable($miningOperation);

        $recipient = $this->isProvided($data, 'recipientMemberId') ? $this->recipientChosenAs($data->recipientMemberId, $miningOperation) : $miningOperation->getFleetStorageRecipient();
        $containerName = $this->isProvided($data, 'containerName') ? $data->containerName : $miningOperation->getFleetStorageContainerName();

        $this->declarations->assign($miningOperation, $recipient, $containerName);

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'fleet_storage_updated',
            ['memberId' => null],
        );

        return $this->mapper->fleetStorageToResource(
            $miningOperation,
            $user,
            true,
            $this->typeNameResolver->findNames($this->mapper->fleetStorageTypeIdsOf($miningOperation)),
        );
    }

    private function isProvided(UpdateMiningFleetStorageInput $input, string $field): bool
    {
        return (new \ReflectionProperty($input, $field))->isInitialized($input);
    }

    private function recipientChosenAs(?string $memberId, MiningOperation $miningOperation): ?MiningOperationMember
    {
        if ($memberId === null) {
            return null;
        }

        $member = Uuid::isValid($memberId) ? $this->memberRepository->find(Uuid::fromString($memberId)) : null;
        if ($member === null || $member->getOperation() !== $miningOperation || $member->getStatus() !== MiningOperationMemberStatus::Accepted) {
            throw new BadRequestHttpException('The fleet storage recipient must be an accepted participant');
        }

        return $member;
    }
}
