<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\ApiResource\Planetary\PlanetaryStockPriceResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Planetary\Stock\PlanetaryStockPriceReader;
use App\Service\Planetary\Stock\PlanetaryStockPriceRow;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryStockPriceResource>
 */
final readonly class PlanetaryStockPriceProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryStockPriceReader $reader,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryStockPriceResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        $settings = PlanetarySettingsResource::forUser($user, $this->defaultMarketStructureId, $this->defaultMarketStructureName);
        $report = $this->reader->read($user, $settings->hubStructureId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $resource = new PlanetaryStockPriceResource();
        $resource->hubStructureId = $settings->hubStructureId;
        $resource->hubStructureName = $settings->hubStructureName;
        $resource->paidPriceDays = PlanetaryPaidPriceCalculator::PAID_PRICE_DAYS;
        $resource->complete = $report->complete;
        $resource->unsyncedWallets = $report->unsyncedWallets;
        $resource->rows = array_map(
            static fn (PlanetaryStockPriceRow $row): array => [
                'typeId' => $row->typeId,
                'typeName' => $row->typeName,
                'paidPrice' => $row->paidPrice,
                'hubSell' => $row->hubSell,
                'jitaSell' => $row->jitaSell,
                'paidVsHubPercent' => $row->paidVsHubPercent,
                'myBuyOrder' => $row->myBuyOrder,
                'buyOrderVsHubPercent' => $row->buyOrderVsHubPercent,
                'trend7dPercent' => $row->trend7dPercent,
                'hubBestBuy' => $row->hubBestBuy,
                'signal' => $row->signal?->value,
            ],
            $report->rows,
        );

        return $resource;
    }
}
