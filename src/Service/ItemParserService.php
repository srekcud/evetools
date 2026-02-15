<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Sde\InvTypeRepository;

/**
 * Service for parsing item lists (EVE Online copy/paste, manual input)
 * and resolving item names to SDE type IDs.
 */
class ItemParserService
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    /**
     * Parse a text block into a list of item names and quantities.
     * Supports various formats: "10x Tritanium", "Tritanium x10", tab-separated, etc.
     * Duplicate names are merged (quantities summed).
     *
     * @return list<array{name: string, quantity: int}>
     */
    public function parseItemList(string $text): array
    {
        $items = [];
        $lines = preg_split('/\r?\n/', trim($text));

        if ($lines === false) {
            return [];
        }

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

    /**
     * Resolve parsed item names to SDE type IDs.
     * Performs exact match first, then case-insensitive fallback.
     *
     * @param list<array{name: string, quantity: int}> $items
     * @return array{found: list<array{typeId: int, typeName: string, quantity: int}>, notFound: list<string>}
     */
    public function resolveItemNames(array $items): array
    {
        $found = [];
        $notFound = [];

        foreach ($items as $item) {
            // Normalize multiple spaces to single space
            $name = (string) preg_replace('/\s+/', ' ', trim($item['name']));
            $quantity = $item['quantity'];

            $type = $this->invTypeRepository->findOneBy(['typeName' => $name]);

            if ($type === null) {
                /** @var list<\App\Entity\Sde\InvType> $types */
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

    /**
     * Parse a single line into an item name and quantity.
     *
     * @return array{name: string, quantity: int}|null
     */
    private function parseLine(string $line): ?array
    {
        $line = (string) preg_replace('/^\s*[-*\x{2022}]\s*/u', '', $line);

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
}
