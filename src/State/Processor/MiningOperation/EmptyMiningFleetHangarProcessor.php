<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningOperation\MiningHaulerPlanResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningHaulerPlanReader;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningHaulerPlanResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, MiningHaulerPlanResource>
 */
final readonly class EmptyMiningFleetHangarProcessor implements ProcessorInterface
{
    private const string FORBIDDEN_MESSAGE = 'Only the fleet storage recipient, the operation owner or an admin can declare the hangar emptied';
    private const string NOT_RUNNING_MESSAGE = 'The operation is not running';
    private const string EMPTIED_ACTION = 'fleet_hangar_emptied';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningHaulerPlanReader $reader,
        private MiningHaulerPlanResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningHaulerPlanResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);
        MiningOperationRequest::assertHasHaulerPlan($miningOperation);

        if (!$this->canDeclareEmptied($user, $miningOperation)) {
            throw new AccessDeniedHttpException(self::FORBIDDEN_MESSAGE);
        }
        if (MiningOperationStatus::InProgress !== $miningOperation->getStatus()) {
            throw new BadRequestHttpException(self::NOT_RUNNING_MESSAGE);
        }

        $miningOperation->markFleetHangarEmptied(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            self::EMPTIED_ACTION,
        );

        return $this->mapper->toResource($this->reader->read($miningOperation));
    }

    private function canDeclareEmptied(User $user, MiningOperation $miningOperation): bool
    {
        return $miningOperation->getFleetStorageRecipient()?->getUser() === $user
            || $this->access->canAdminister($user, $miningOperation);
    }
}
