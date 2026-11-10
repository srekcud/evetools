<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSellForecastResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ForecastedProduct;
use App\Service\Industry\Project\ProjectSellForecast;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\Service\Industry\PartialPricePresenter;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectSellForecastResource>
 */
class ProjectSellForecastProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectSellForecast $sellForecast,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectSellForecastResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $forecast = $this->sellForecast->forecast($project, $this->clock->now());

        $resource = new ProjectSellForecastResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->sellStructure = null === $forecast->sellStructure ? null : ['id' => $forecast->sellStructure->id, 'name' => $forecast->sellStructure->name];
        $resource->products = array_map($this->productLine(...), $forecast->products);
        $resource->totals = [
            'structureNetRevenue' => $forecast->structureNetRevenue,
            'jitaNetRevenue' => $forecast->jitaNetRevenue,
            'structureUnknownProductCount' => $forecast->structureUnknownProductCount,
            'jitaUnknownProductCount' => $forecast->jitaUnknownProductCount,
            'remainingCost' => $forecast->remainingCost,
            'remainingCostUnknownLineCount' => $forecast->remainingCostUnknownLineCount,
            'structureProfit' => $forecast->structureProfit,
            'jitaProfit' => $forecast->jitaProfit,
        ];

        return $resource;
    }

    /**
     * @return array{typeId: int, typeName: string, quantity: int, structure: array{name: string, unitPrice: float|null, coveragePercent: float|null, feeRate: float|null, netUnitPrice: float|null, netRevenue: float|null, daysToSell: float|null}|null, jita: array{name: string, unitPrice: float|null, coveragePercent: float|null, feeRate: float|null, netUnitPrice: float|null, netRevenue: float|null, daysToSell: float|null}}
     */
    private function productLine(ForecastedProduct $product): array
    {
        return [
            'typeId' => $product->typeId,
            'typeName' => $product->typeName,
            'quantity' => $product->quantity,
            'structure' => null === $product->structure ? null : $this->marketLine($product->structure, $product->structureNetRevenue()),
            'jita' => $this->marketLine($product->jita, $product->jitaNetRevenue()),
        ];
    }

    /**
     * @return array{name: string, unitPrice: float|null, coveragePercent: float|null, feeRate: float|null, netUnitPrice: float|null, netRevenue: float|null, daysToSell: float|null}
     */
    private function marketLine(SellMarketRow $row, ?float $netRevenue): array
    {
        return [
            'name' => $row->name,
            'unitPrice' => $row->unitPrice,
            'coveragePercent' => null === $row->coverage ? null : PartialPricePresenter::coveragePercentOfFraction($row->coverage),
            'feeRate' => $row->feeRate,
            'netUnitPrice' => $row->netUnitPrice,
            'netRevenue' => $netRevenue,
            'daysToSell' => $row->daysToSell,
        ];
    }
}
