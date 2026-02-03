<?php

declare(strict_types=1);

namespace App\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ShoppingList\ParseListInput;
use App\ApiResource\ShoppingList\ParseResultResource;
use App\ApiResource\ShoppingList\ShoppingListItemResource;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\MarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ParseListInput, ParseResultResource>
 */
class ParseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly MarketService $marketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ParseResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof ParseListInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        if (empty(trim($data->text))) {
            throw new BadRequestHttpException('No text provided');
        }

        $structureId = $data->structureId ?? $user->getPreferredMarketStructureId();
        $transportCostPerM3 = $data->transportCost;

        $parsedItems = $this->parseItemList($data->text);

        if (empty($parsedItems)) {
            throw new BadRequestHttpException('No items could be parsed from the text');
        }

        $resolvedItems = $this->resolveItemNames($parsedItems);

        $resource = new ParseResultResource();
        $resource->transportCostPerM3 = $transportCostPerM3;
        $resource->structureId = $structureId;

        if (empty($resolvedItems['found'])) {
            $resource->items = [];
            $resource->notFound = $resolvedItems['notFound'];
            $resource->totals = $this->emptyTotals();

            return $resource;
        }

        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        $typeIds = array_column($resolvedItems['found'], 'typeId');

        $volumes = [];
        foreach ($this->invTypeRepository->findBy(['typeId' => $typeIds]) as $type) {
            $volumes[$type->getTypeId()] = $type->getVolume() ?? 0.0;
        }

        $priceData = null;
        $priceError = null;
        try {
            $priceData = $this->marketService->comparePrices($typeIds, $structureId, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch market prices for shopping list', [
                'error' => $e->getMessage(),
            ]);
            $priceError = 'Unable to fetch market prices. Please try again later.';
        }

        $items = [];
        $totalJita = 0.0;
        $totalImport = 0.0;
        $totalJitaWithImport = 0.0;
        $totalStructure = 0.0;
        $totalBest = 0.0;
        $totalVolume = 0.0;

        foreach ($resolvedItems['found'] as $item) {
            $typeId = $item['typeId'];
            $quantity = $item['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

            $jitaPrice = $priceData !== null ? ($priceData['jita'][$typeId] ?? null) : null;
            $structurePrice = $priceData !== null ? ($priceData['structure'][$typeId] ?? null) : null;

            $jitaTotal = $jitaPrice !== null ? $jitaPrice * $quantity : null;
            $structureTotal = $structurePrice !== null ? $structurePrice * $quantity : null;

            $importCost = $totalItemVolume * $transportCostPerM3;
            $jitaWithImport = $jitaTotal !== null ? $jitaTotal + $importCost : null;

            $bestLocation = null;
            $bestTotal = null;
            if ($jitaWithImport !== null && $structureTotal !== null) {
                if ($jitaWithImport <= $structureTotal) {
                    $bestLocation = 'jita';
                    $bestTotal = $jitaWithImport;
                } else {
                    $bestLocation = 'structure';
                    $bestTotal = $structureTotal;
                }
            } elseif ($jitaWithImport !== null) {
                $bestLocation = 'jita';
                $bestTotal = $jitaWithImport;
            } elseif ($structureTotal !== null) {
                $bestLocation = 'structure';
                $bestTotal = $structureTotal;
            }

            $itemResource = new ShoppingListItemResource();
            $itemResource->typeId = $typeId;
            $itemResource->typeName = $item['typeName'];
            $itemResource->quantity = $quantity;
            $itemResource->volume = $volume;
            $itemResource->totalVolume = round($totalItemVolume, 2);
            $itemResource->jitaPrice = $jitaPrice;
            $itemResource->jitaTotal = $jitaTotal;
            $itemResource->importCost = round($importCost, 2);
            $itemResource->jitaWithImport = $jitaWithImport !== null ? round($jitaWithImport, 2) : null;
            $itemResource->structurePrice = $structurePrice;
            $itemResource->structureTotal = $structureTotal;
            $itemResource->bestLocation = $bestLocation;
            $itemResource->bestTotal = $bestTotal !== null ? round($bestTotal, 2) : null;

            $items[] = $itemResource;

            if ($jitaTotal !== null) {
                $totalJita += $jitaTotal;
            }
            $totalImport += $importCost;
            if ($jitaWithImport !== null) {
                $totalJitaWithImport += $jitaWithImport;
            }
            if ($structureTotal !== null) {
                $totalStructure += $structureTotal;
            }
            if ($bestTotal !== null) {
                $totalBest += $bestTotal;
            }
            $totalVolume += $totalItemVolume;
        }

        $structureLastSync = null;
        if ($priceData !== null && isset($priceData['structureLastSync']) && $priceData['structureLastSync'] instanceof \DateTimeImmutable) {
            $structureLastSync = $priceData['structureLastSync']->format('c');
        }

        $resource->items = $items;
        $resource->notFound = $resolvedItems['notFound'];
        $resource->totals = [
            'jita' => round($totalJita, 2),
            'import' => round($totalImport, 2),
            'jitaWithImport' => round($totalJitaWithImport, 2),
            'structure' => round($totalStructure, 2),
            'best' => round($totalBest, 2),
            'volume' => round($totalVolume, 2),
        ];
        $resource->structureName = $priceData['structureName'] ?? null;
        $resource->structureAccessible = $priceData !== null && ($priceData['structureAccessible'] ?? false);
        $resource->structureFromCache = $priceData !== null && ($priceData['structureFromCache'] ?? false);
        $resource->structureLastSync = $structureLastSync;
        $resource->priceError = $priceError;

        return $resource;
    }

    private function parseItemList(string $text): array
    {
        $items = [];
        $lines = preg_split('/\r?\n/', trim($text));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed !== null) {
                $found = false;
                foreach ($items as &$item) {
                    if (strcasecmp($item['name'], $parsed['name']) === 0) {
                        $item['quantity'] += $parsed['quantity'];
                        $found = true;
                        break;
                    }
                }
                unset($item);

                if (!$found) {
                    $items[] = $parsed;
                }
            }
        }

        return $items;
    }

    private function parseLine(string $line): ?array
    {
        $line = preg_replace('/^\s*[-*•]\s*/', '', $line);

        if (preg_match('/^(\d[\d,\s]*)\s*x\s+(.+)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[1]);

            return ['name' => trim($matches[2]), 'quantity' => max(1, $quantity)];
        }

        if (preg_match('/^(.+?)\s+x\s*(\d[\d,\s]*)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);

            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        if (preg_match('/^(.+?)\t+(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);

            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        if (preg_match('/^(.+?)\s{2,}(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);

            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        if (preg_match('/^(.+?)\s+(\d[\d,]*)$/', $line, $matches)) {
            $name = trim($matches[1]);
            if (!preg_match('/^\d/', $name)) {
                $quantity = (int) str_replace([',', ' '], '', $matches[2]);

                return ['name' => $name, 'quantity' => max(1, $quantity)];
            }
        }

        if (preg_match('/^[a-zA-Z]/', $line)) {
            return ['name' => $line, 'quantity' => 1];
        }

        return null;
    }

    private function resolveItemNames(array $items): array
    {
        $found = [];
        $notFound = [];

        foreach ($items as $item) {
            // Normalize multiple spaces to single space
            $name = preg_replace('/\s+/', ' ', trim($item['name']));
            $quantity = $item['quantity'];

            $type = $this->invTypeRepository->findOneBy(['typeName' => $name]);

            if ($type === null) {
                $types = $this->invTypeRepository->createQueryBuilder('t')
                    ->where('LOWER(t.typeName) = LOWER(:name)')
                    ->setParameter('name', $name)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getResult();

                $type = $types[0] ?? null;
            }

            if ($type !== null && $type->isPublished()) {
                $found[] = [
                    'typeId' => $type->getTypeId(),
                    'typeName' => $type->getTypeName(),
                    'quantity' => $quantity,
                ];
            } else {
                $notFound[] = $name;
            }
        }

        return ['found' => $found, 'notFound' => $notFound];
    }

    private function emptyTotals(): array
    {
        return [
            'jita' => 0.0,
            'import' => 0.0,
            'jitaWithImport' => 0.0,
            'structure' => 0.0,
            'best' => 0.0,
            'volume' => 0.0,
        ];
    }
}
