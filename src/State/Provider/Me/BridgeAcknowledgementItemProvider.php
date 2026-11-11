<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\BridgeAcknowledgementResource;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BridgeAcknowledgementResource>
 */
class BridgeAcknowledgementItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly BridgeAcknowledgementRepository $acknowledgementRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BridgeAcknowledgementResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $itemKey = BridgeTodoKey::tryFrom((string) $uriVariables['itemKey']);
        $acknowledgement = $itemKey === null ? null : $this->acknowledgementRepository->findByUserAndItemKey($user, $itemKey);

        if ($acknowledgement === null) {
            throw new NotFoundHttpException('Acknowledgement not found');
        }

        $resource = new BridgeAcknowledgementResource();
        $resource->itemKey = $acknowledgement->getItemKey()->value;
        $resource->acknowledgedCount = $acknowledgement->getAcknowledgedCount();

        return $resource;
    }
}
