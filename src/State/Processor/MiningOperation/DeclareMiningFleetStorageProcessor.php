<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\DeclareMiningFleetStorageInput;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Enum\MiningFleetStorageDelivery;
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

/**
 * @implements ProcessorInterface<DeclareMiningFleetStorageInput, MiningFleetStorageResource>
 */
final readonly class DeclareMiningFleetStorageProcessor implements ProcessorInterface
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

        \assert($data instanceof DeclareMiningFleetStorageInput);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $member = MiningOperationRequest::memberOf($this->memberRepository, $miningOperation, $uriVariables['memberId']);

        $this->declarations->declare(
            $member,
            MiningFleetStorageDelivery::from($data->delivery ?? throw new \LogicException('A validated declaration has a delivery')),
            $this->quantitiesByTypeIdOf($data),
        );

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'fleet_storage_updated',
            ['memberId' => PersistedUuid::of($member->getId())],
        );

        return $this->mapper->fleetStorageToResource(
            $miningOperation,
            $user,
            $this->access->canAdminister($user, $miningOperation),
            $this->typeNameResolver->findNames($this->mapper->fleetStorageTypeIdsOf($miningOperation)),
        );
    }

    /**
     * @return array<int, int>|null
     */
    private function quantitiesByTypeIdOf(DeclareMiningFleetStorageInput $input): ?array
    {
        if ($input->delivery !== MiningFleetStorageDelivery::Partial->value) {
            return null;
        }

        $quantities = [];
        foreach ($input->quantities ?? [] as $line) {
            $quantities[(int) $line['typeId']] = (int) $line['quantity'];
        }

        return $quantities;
    }
}
