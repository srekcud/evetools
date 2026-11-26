<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPaidPriceResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryPaidPriceResource>
 */
final readonly class PlanetaryPaidPriceProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPaidPriceCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPaidPriceResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $report = $this->calculator->calculate($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $resource = new PlanetaryPaidPriceResource();
        $resource->days = $report['days'];
        $resource->since = $report['since'];
        $resource->prices = $report['prices'];
        $resource->unsyncedWallets = $report['unsyncedWallets'];

        return $resource;
    }
}
