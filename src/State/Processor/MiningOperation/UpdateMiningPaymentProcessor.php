<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\UpdateMiningPaymentInput;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationNotifier;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningOperationSettlement;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<UpdateMiningPaymentInput, MiningPaymentResource>
 */
final readonly class UpdateMiningPaymentProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationSettlement $settlement,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
        private MiningOperationNotifier $notifier,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningPaymentResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof UpdateMiningPaymentInput);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $payment = MiningOperationRequest::paymentOf($miningOperation, $uriVariables['id']);

        $paid = $data->paid ?? throw new \LogicException('The paid flag is validated before the processor runs');
        $wasPaid = $payment->isPaid();

        $this->settlement->mark($payment, $paid, $user);

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'payment_updated',
            ['paymentId' => PersistedUuid::of($payment->getId()), 'paid' => $payment->isPaid()],
        );

        if ($paid && !$wasPaid) {
            $this->notifier->paymentMarkedPaid($payment, $user);
        }

        return $this->mapper->paymentToResource($payment);
    }
}
