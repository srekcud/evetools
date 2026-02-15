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

        // Fetch sell and buy prices
        $priceError = null;
        $sellPrices = [];
        $buyPrices = [];
        try {
            $sellPrices = $this->jitaMarketService->getPrices($typeIds);
            $buyPrices = $this->jitaMarketService->getBuyPrices($typeIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch Jita prices for appraisal', [
                'error' => $e->getMessage(),
            ]);
            $priceError = 'Unable to fetch market prices. Please try again later.';
        }

        $items = [];
        $totalSell = 0.0;
        $totalBuy = 0.0;
        $totalSplit = 0.0;
        $totalVolume = 0.0;

        foreach ($resolvedItems['found'] as $item) {
            $typeId = $item['typeId'];
            $quantity = $item['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

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
            $totalVolume += $totalItemVolume;
        }

        $resource->items = $items;
        $resource->notFound = $resolvedItems['notFound'];
        $resource->totals = [
            'sellTotal' => round($totalSell, 2),
            'buyTotal' => round($totalBuy, 2),
            'splitTotal' => round($totalSplit, 2),
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
            'volume' => 0.0,
        ];
    }
}
