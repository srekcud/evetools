<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Repository\MarketPriceAlertRepository;
use App\Service\MarketAlertPriceResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<MarketAlertResource>
 */
class MarketAlertCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketPriceAlertRepository $alertRepository,
        private readonly MarketAlertPriceResolver $priceResolver,
    ) {
    }

    /**
     * @return MarketAlertResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $alerts = $this->alertRepository->findByUser($user);

        if (empty($alerts)) {
            return [];
        }

        $currentPrices = $this->priceResolver->currentPrices($user, $alerts);

        return array_map(
            static fn (MarketPriceAlert $alert, ?float $currentPrice): MarketAlertResource => MarketAlertResourceMapper::toResource(
                $alert,
                $currentPrice,
                MarketAlertResourceMapper::structureNameOf($user, $alert),
            ),
            $alerts,
            $currentPrices,
        );
    }
}
