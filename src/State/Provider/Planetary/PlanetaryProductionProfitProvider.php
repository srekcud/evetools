<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryProductionProfitResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryProductionProfitCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryProductionProfitResource>
 */
final readonly class PlanetaryProductionProfitProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryProductionProfitCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryProductionProfitResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $report = $this->calculator->calculate($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $resource = new PlanetaryProductionProfitResource();
        $resource->hub = $report['hub'];
        $resource->lines = $report['lines'];
        $resource->salesIsk = $report['salesIsk'];
        $resource->inputsIsk = $report['inputsIsk'];
        $resource->profitPerDay = $report['profitPerDay'];
        $resource->unknownLineCount = $report['unknownLineCount'];
        $resource->taxes = ['days' => $report['taxDays'], 'perDay' => $report['taxesPerDay']];
        $resource->netProfitPerDay = $report['netProfitPerDay'];
        $resource->unsyncedWallets = $report['unsyncedWallets'];

        return $resource;
    }
}
