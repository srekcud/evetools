<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\CorrectMinedQuantityInput;
use App\Enum\MinedQuantityState;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<CorrectMinedQuantityInput, null>
 */
final readonly class CorrectMinedQuantityProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationCharacterRepository $operationCharacterRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private TypeNameResolver $typeNameResolver,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof CorrectMinedQuantityInput);
        \assert($data->typeId !== null && $data->quantity !== null);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $operationCharacter = MiningOperationRequest::operationCharacterOf($this->operationCharacterRepository, $miningOperation, $uriVariables['id']);

        $this->access->assertCanAdminister($user, $miningOperation);

        if ($miningOperation->getStatus() !== MiningOperationStatus::Closing) {
            throw new BadRequestHttpException('Quantities can only be corrected while the operation is closing');
        }

        $measured = $this->calculator->measuredOf($operationCharacter);
        if ($measured->state === MinedQuantityState::Measuring) {
            throw new BadRequestHttpException('This quantity is still being measured');
        }

        if ($this->typeNameResolver->findName($data->typeId) === null) {
            throw new BadRequestHttpException('Unknown type');
        }

        $originalQuantity = $measured->state === MinedQuantityState::Known
            ? (array_key_exists($data->typeId, $measured->quantitiesByTypeId) ? $measured->quantitiesByTypeId[$data->typeId] : 0)
            : null;
        $operationCharacter->correct($data->typeId, $originalQuantity, $data->quantity, $user, $this->clock->now());
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'quantity_corrected',
            ['operationCharacterId' => PersistedUuid::of($operationCharacter->getId()), 'typeId' => $data->typeId],
        );

        return null;
    }
}
