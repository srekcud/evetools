<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProviderInterface<MiningPaymentResource>
 */
final readonly class MiningPaymentItemProvider implements ProviderInterface
{
    private const string NOT_THE_PAYER_MESSAGE = 'Only the payer, the operation owner or an admin can mark this payment';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningPaymentResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);

        $payment = MiningOperationRequest::paymentOf($miningOperation, $uriVariables['id']);

        $payerMember = $payment->getPayerMember();
        if (!$this->access->canAdminister($user, $miningOperation) && ($payerMember === null || $payerMember !== $miningOperation->activeMembershipOf($user))) {
            throw new AccessDeniedHttpException(self::NOT_THE_PAYER_MESSAGE);
        }

        return $this->mapper->paymentToResource($payment);
    }
}
