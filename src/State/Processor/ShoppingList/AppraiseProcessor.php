<?php

declare(strict_types=1);

namespace App\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ShoppingList\AppraiseInput;
use App\ApiResource\ShoppingList\AppraisalItemResource;
use App\ApiResource\ShoppingList\AppraisalResultResource;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ItemParserService;
use App\Service\JitaMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<AppraiseInput, AppraisalResultResource>
 */
class AppraiseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ItemParserService $itemParserService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AppraisalResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof AppraiseInput);

        if (empty(trim($data->text))) {
            throw new BadRequestHttpException('No text provided');
        }

        $parsedItems = $this->itemParserService->parseItemList($data->text);

        if (empty($parsedItems)) {
            throw new BadRequestHttpException('No items could be parsed from the text');
        }

        $resolvedItems = $this->itemParserService->resolveItemNames($parsedItems);

        $resource = new AppraisalResultResource();

        if (empty($resolvedItems['found'])) {
            $resource->items = [];
            $resource->notFound = $resolvedItems['notFound'];
            $resource->totals = $this->emptyTotals();

            return $resource;
        }

        $typeIds = array_column($resolvedItems['found'], 'typeId');

        // Fetch volumes from SDE
        $volumes = [];
        foreach ($this->invTypeRepository->findBy(['typeId' => $typeIds]) as $type) {
            $volumes[$type->getTypeId()] = $type->getVolume() ?? 0.0;
        }

        // Fetch sell and buy prices (best prices)
        $priceError = null;
        $sellPrices = [];
        $buyPrices = [];
        $weightedSellPrices = [];
        $weightedBuyPrices = [];
        try {
            $sellPrices = $this->jitaMarketService->getPrices($typeIds);
            $buyPrices = $this->jitaMarketService->getBuyPrices($typeIds);

            // Build typeId => quantity map for weighted price calculation
            $typeQuantities = [];
            foreach ($resolvedItems['found'] as $item) {
                $typeId = $item['typeId'];
                $typeQuantities[$typeId] = ($typeQuantities[$typeId] ?? 0) + $item['quantity'];
            }

            $weightedSellPrices = $this->jitaMarketService->getWeightedSellPrices($typeQuantities);
            $weightedBuyPrices = $this->jitaMarketService->getWeightedBuyPrices($typeQuantities);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch Jita prices for appraisal', [
                'error' => $e->getMessage(),
            ]);
            $priceError = 'Unable to fetch market prices. Please try again later.';
        }

        // Fetch average daily volumes (non-critical, failures are silently ignored)
        $avgDailyVolumes = [];
        try {
            $avgDailyVolumes = $this->jitaMarketService->getAverageDailyVolumes($typeIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch average daily volumes for appraisal', [
                'error' => $e->getMessage(),
            ]);
        }

        $items = [];
        $totalSell = 0.0;
        $totalBuy = 0.0;
        $totalSplit = 0.0;
        $totalSellWeighted = 0.0;
        $totalBuyWeighted = 0.0;
        $totalSplitWeighted = 0.0;
        $totalVolume = 0.0;

        foreach ($resolvedItems['found'] as $item) {
            $typeId = $item['typeId'];
            $quantity = $item['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

            // Best prices (existing behavior)
            $sellPrice = $sellPrices[$typeId] ?? null;
            $buyPrice = $buyPrices[$typeId] ?? null;

            $sellTotal = $sellPrice !== null ? $sellPrice * $quantity : null;
            $buyTotal = $buyPrice !== null ? $buyPrice * $quantity : null;

            $splitPrice = null;
            $splitTotal = null;
            if ($sellPrice !== null && $buyPrice !== null) {
                $splitPrice = ($sellPrice + $buyPrice) / 2;
                $splitTotal = $splitPrice * $quantity;
            }

            // Weighted prices
            $weightedSell = $weightedSellPrices[$typeId] ?? null;
            $weightedBuy = $weightedBuyPrices[$typeId] ?? null;

            $sellPriceWeighted = $weightedSell !== null ? $weightedSell['weightedPrice'] : null;
            $sellTotalWeighted = $sellPriceWeighted !== null ? $sellPriceWeighted * $quantity : null;
            $buyPriceWeighted = $weightedBuy !== null ? $weightedBuy['weightedPrice'] : null;
            $buyTotalWeighted = $buyPriceWeighted !== null ? $buyPriceWeighted * $quantity : null;

            $splitPriceWeighted = null;
            $splitTotalWeighted = null;
            if ($sellPriceWeighted !== null && $buyPriceWeighted !== null) {
                $splitPriceWeighted = ($sellPriceWeighted + $buyPriceWeighted) / 2;
                $splitTotalWeighted = $splitPriceWeighted * $quantity;
            }

            $itemResource = new AppraisalItemResource();
            $itemResource->typeId = $typeId;
            $itemResource->typeName = $item['typeName'];
            $itemResource->quantity = $quantity;
            $itemResource->volume = $volume;
            $itemResource->totalVolume = round($totalItemVolume, 2);
            $itemResource->sellPrice = $sellPrice;
            $itemResource->sellTotal = $sellTotal !== null ? round($sellTotal, 2) : null;
            $itemResource->buyPrice = $buyPrice;
            $itemResource->buyTotal = $buyTotal !== null ? round($buyTotal, 2) : null;
            $itemResource->splitPrice = $splitPrice !== null ? round($splitPrice, 2) : null;
            $itemResource->splitTotal = $splitTotal !== null ? round($splitTotal, 2) : null;
            $itemResource->sellPriceWeighted = $sellPriceWeighted !== null ? round($sellPriceWeighted, 2) : null;
            $itemResource->sellTotalWeighted = $sellTotalWeighted !== null ? round($sellTotalWeighted, 2) : null;
            $itemResource->buyPriceWeighted = $buyPriceWeighted !== null ? round($buyPriceWeighted, 2) : null;
            $itemResource->buyTotalWeighted = $buyTotalWeighted !== null ? round($buyTotalWeighted, 2) : null;
            $itemResource->splitPriceWeighted = $splitPriceWeighted !== null ? round($splitPriceWeighted, 2) : null;
            $itemResource->splitTotalWeighted = $splitTotalWeighted !== null ? round($splitTotalWeighted, 2) : null;
            $itemResource->sellCoverage = $weightedSell !== null ? round($weightedSell['coverage'], 4) : null;
            $itemResource->buyCoverage = $weightedBuy !== null ? round($weightedBuy['coverage'], 4) : null;
            $itemResource->avgDailyVolume = $avgDailyVolumes[$typeId] ?? null;

            $items[] = $itemResource;

            if ($sellTotal !== null) {
                $totalSell += $sellTotal;
            }
            if ($buyTotal !== null) {
                $totalBuy += $buyTotal;
            }
            if ($splitTotal !== null) {
                $totalSplit += $splitTotal;
            }
            if ($sellTotalWeighted !== null) {
                $totalSellWeighted += $sellTotalWeighted;
            }
            if ($buyTotalWeighted !== null) {
                $totalBuyWeighted += $buyTotalWeighted;
            }
            if ($splitTotalWeighted !== null) {
                $totalSplitWeighted += $splitTotalWeighted;
            }
            $totalVolume += $totalItemVolume;
        }

        $resource->items = $items;
        $resource->notFound = $resolvedItems['notFound'];
        $resource->totals = [
            'sellTotal' => round($totalSell, 2),
            'buyTotal' => round($totalBuy, 2),
            'splitTotal' => round($totalSplit, 2),
            'sellTotalWeighted' => round($totalSellWeighted, 2),
            'buyTotalWeighted' => round($totalBuyWeighted, 2),
            'splitTotalWeighted' => round($totalSplitWeighted, 2),
            'volume' => round($totalVolume, 2),
        ];
        $resource->priceError = $priceError;

        return $resource;
    }

    /** @return array<string, float> */
    private function emptyTotals(): array
    {
        return [
            'sellTotal' => 0.0,
            'buyTotal' => 0.0,
            'splitTotal' => 0.0,
            'sellTotalWeighted' => 0.0,
            'buyTotalWeighted' => 0.0,
            'splitTotalWeighted' => 0.0,
            'volume' => 0.0,
        ];
    }
}
