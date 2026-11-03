<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Entity\MiningPayment;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningPaymentResource>
 */
final readonly class MiningPaymentCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningPaymentResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanReadResults($user, $miningOperation);

        $payments = array_values($miningOperation->getPayments()->toArray());
        usort($payments, static fn (MiningPayment $left, MiningPayment $right): int => $left->getPosition() <=> $right->getPosition());

        return array_map($this->mapper->paymentToResource(...), $payments);
    }
}
