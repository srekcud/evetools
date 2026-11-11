<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\BridgeAcknowledgementResource;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Repository\BridgeAcknowledgementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BridgeAcknowledgementResource>
 */
class BridgeAcknowledgementCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly BridgeAcknowledgementRepository $acknowledgementRepository,
    ) {
    }

    /**
     * @return BridgeAcknowledgementResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(
            static function (BridgeAcknowledgement $acknowledgement): BridgeAcknowledgementResource {
                $resource = new BridgeAcknowledgementResource();
                $resource->itemKey = $acknowledgement->getItemKey()->value;
                $resource->acknowledgedCount = $acknowledgement->getAcknowledgedCount();

                return $resource;
            },
            $this->acknowledgementRepository->findByUser($user),
        );
    }
}
