<?php

declare(strict_types=1);

namespace App\Service\Planetary\Layout;

use App\Entity\Sde\InvType;
use App\Exception\InvalidPlanetaryColonyLayoutException;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;

final readonly class PlanetaryColonyLayoutParser
{
    private const int MAX_TEMPLATE_BYTES = 65_536;
    private const int MAX_PINS = 200;
    private const int MAX_LINKS = 400;
    private const int MAX_ROUTES = 600;
    private const int MIN_COMMAND_CENTER_LEVEL = 0;
    private const int MAX_COMMAND_CENTER_LEVEL = 5;
    private const int MIN_LINK_LEVEL = 0;
    private const int MAX_LINK_LEVEL = 5;
    private const int MIN_EXTRACTOR_HEADS = 1;
    private const int MAX_EXTRACTOR_HEADS = 10;
    private const int MIN_ROUTE_QUANTITY = 1;
    private const int MAX_ROUTE_QUANTITY = 10_000_000;
    private const int MIN_ROUTE_PATH_LENGTH = 2;
    private const int MAX_TYPE_ID = 2_147_483_647;

    private const int PLANET_GROUP_ID = 7;
    private const int PROCESSOR_GROUP_ID = 1028;
    private const int EXTRACTOR_CONTROL_UNIT_GROUP_ID = 1063;
    private const array INSTALLATION_GROUP_IDS = [1027, 1028, 1029, 1030, 1063];
    private const array EXTRACTED_RESOURCE_GROUP_IDS = [1032, 1033, 1035];
    private const array ROUTED_COMMODITY_GROUP_IDS = [1032, 1033, 1034, 1035, 1040, 1041, 1042];

    private const string ROOT_PATH = '$';
    private const string CODE_MISSING = 'missing';
    private const string CODE_INVALID_TYPE = 'invalid_type';
    private const string CODE_OUT_OF_RANGE = 'out_of_range';

    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $json): array
    {
        if (\strlen($json) > self::MAX_TEMPLATE_BYTES) {
            throw new InvalidPlanetaryColonyLayoutException([['path' => self::ROOT_PATH, 'code' => 'too_large']]);
        }

        $layout = $this->decodeObject($json);
        $types = $this->referencedTypes($layout);
        $errors = [];

        $commandCenterLevel = $this->integerField($layout, 'CmdCtrLv', 'CmdCtrLv', self::MIN_COMMAND_CENTER_LEVEL, self::MAX_COMMAND_CENTER_LEVEL, $errors);
        $comment = $this->commentField($layout, $errors);
        $diameter = $this->diameterField($layout, $errors);
        $planetTypeId = $this->planetField($layout, $types, $errors);
        $pins = $this->pinsField($layout, $types, $errors);
        $pinCount = $pins === null ? null : \count($pins);
        $links = $this->linksField($layout, $pinCount, $errors);
        $routes = $this->routesField($layout, $types, $pinCount, $links === null ? null : $this->linkedPairs($links), $errors);

        if ($errors !== []) {
            throw new InvalidPlanetaryColonyLayoutException($errors);
        }

        $normalized = ['CmdCtrLv' => $commandCenterLevel];
        if ($comment !== null) {
            $normalized['Cmt'] = $comment;
        }

        return $normalized + [
            'Diam' => $diameter,
            'L' => $links,
            'P' => $pins,
            'Pln' => $planetTypeId,
            'R' => $routes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPlanetaryColonyLayoutException([['path' => self::ROOT_PATH, 'code' => 'invalid_json']]);
        }

        if (!\is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new InvalidPlanetaryColonyLayoutException([['path' => self::ROOT_PATH, 'code' => 'invalid_json']]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, InvType>
     */
    private function referencedTypes(array $layout): array
    {
        $typeIds = [];
        $this->collectTypeId($typeIds, $layout['Pln'] ?? null);
        foreach ($this->entries($layout['P'] ?? null) as $pin) {
            $this->collectTypeId($typeIds, $pin['T'] ?? null);
            $this->collectTypeId($typeIds, $pin['S'] ?? null);
        }
        foreach ($this->entries($layout['R'] ?? null) as $route) {
            $this->collectTypeId($typeIds, $route['T'] ?? null);
        }

        if ($typeIds === []) {
            return [];
        }

        return $this->invTypeRepository->findByTypeIds(array_values($typeIds));
    }

    /**
     * @param array<int, int> $typeIds
     */
    private function collectTypeId(array &$typeIds, mixed $candidate): void
    {
        if (\is_int($candidate) && $candidate >= 0 && $candidate <= self::MAX_TYPE_ID) {
            $typeIds[$candidate] = $candidate;
        }
    }

    /**
     * @return list<array<mixed>>
     */
    private function entries(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_array(...)));
    }

    /**
     * @param array<string, mixed> $layout
     * @param list<array{path: string, code: string}> $errors
     */
    private function commentField(array $layout, array &$errors): ?string
    {
        $comment = $layout['Cmt'] ?? null;
        if ($comment === null) {
            return null;
        }
        if (!\is_string($comment)) {
            $errors[] = ['path' => 'Cmt', 'code' => self::CODE_INVALID_TYPE];

            return null;
        }

        return $comment;
    }

    /**
     * @param array<string, mixed> $layout
     * @param list<array{path: string, code: string}> $errors
     */
    private function diameterField(array $layout, array &$errors): int|float|null
    {
        $diameter = $this->numberField($layout, 'Diam', 'Diam', $errors);
        if ($diameter !== null && $diameter <= 0) {
            $errors[] = ['path' => 'Diam', 'code' => self::CODE_OUT_OF_RANGE];

            return null;
        }

        return $diameter;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, InvType> $types
     * @param list<array{path: string, code: string}> $errors
     */
    private function planetField(array $layout, array $types, array &$errors): ?int
    {
        $planetTypeId = $this->requiredInteger($layout, 'Pln', 'Pln', $errors);
        if ($planetTypeId === null) {
            return null;
        }
        if ($this->groupIdOf($planetTypeId, $types) !== self::PLANET_GROUP_ID) {
            $errors[] = ['path' => 'Pln', 'code' => 'unknown_planet'];

            return null;
        }

        return $planetTypeId;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, InvType> $types
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<array<string, mixed>>|null
     */
    private function pinsField(array $layout, array $types, array &$errors): ?array
    {
        $pins = $this->requiredList($layout, 'P', 'P', $errors);
        if ($pins === null) {
            return null;
        }
        if (\count($pins) > self::MAX_PINS) {
            $errors[] = ['path' => 'P', 'code' => 'too_many_pins'];

            return null;
        }

        $schematicOutputTypeIds = $this->schematicOutputTypeIds();
        $normalizedPins = [];
        $hasProducer = false;
        foreach ($pins as $index => $pin) {
            $path = 'P['.$index.']';
            if (!\is_array($pin) || array_is_list($pin)) {
                $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];
                $normalizedPins[] = [];
                continue;
            }
            $typeId = $this->requiredInteger($pin, 'T', $path.'.T', $errors);
            $groupId = $typeId === null ? null : $this->groupIdOf($typeId, $types);
            if ($typeId !== null && !\in_array($groupId, self::INSTALLATION_GROUP_IDS, true)) {
                $errors[] = ['path' => $path.'.T', 'code' => 'unknown_installation'];
            }
            $hasProducer = $hasProducer || $groupId === self::PROCESSOR_GROUP_ID || $groupId === self::EXTRACTOR_CONTROL_UNIT_GROUP_ID;

            $normalizedPins[] = [
                'H' => $this->headsField($pin, $path, $groupId, $errors),
                'La' => $this->numberField($pin, 'La', $path.'.La', $errors),
                'Lo' => $this->numberField($pin, 'Lo', $path.'.Lo', $errors),
                'S' => $this->productField($pin, $path, $groupId, $types, $schematicOutputTypeIds, $errors),
                'T' => $typeId,
            ];
        }

        if (!$hasProducer) {
            $errors[] = ['path' => 'P', 'code' => 'empty_layout'];
        }

        return $normalizedPins;
    }

    /**
     * @param array<string, mixed> $pin
     * @param list<array{path: string, code: string}> $errors
     */
    private function headsField(array $pin, string $path, ?int $groupId, array &$errors): ?int
    {
        if ($groupId === self::EXTRACTOR_CONTROL_UNIT_GROUP_ID) {
            return $this->integerField($pin, 'H', $path.'.H', self::MIN_EXTRACTOR_HEADS, self::MAX_EXTRACTOR_HEADS, $errors);
        }
        if (!\array_key_exists('H', $pin)) {
            return 0;
        }

        return $this->integerField($pin, 'H', $path.'.H', 0, 0, $errors);
    }

    /**
     * @param array<string, mixed> $pin
     * @param array<int, InvType> $types
     * @param array<int, true> $schematicOutputTypeIds
     * @param list<array{path: string, code: string}> $errors
     */
    private function productField(array $pin, string $path, ?int $groupId, array $types, array $schematicOutputTypeIds, array &$errors): ?int
    {
        $isProcessor = $groupId === self::PROCESSOR_GROUP_ID;
        if (!$isProcessor && $groupId !== self::EXTRACTOR_CONTROL_UNIT_GROUP_ID) {
            if (($pin['S'] ?? null) !== null) {
                $errors[] = ['path' => $path.'.S', 'code' => self::CODE_INVALID_TYPE];
            }

            return null;
        }

        $productTypeId = $this->requiredInteger($pin, 'S', $path.'.S', $errors);
        if ($productTypeId === null) {
            return null;
        }
        $isKnown = $isProcessor
            ? isset($schematicOutputTypeIds[$productTypeId])
            : \in_array($this->groupIdOf($productTypeId, $types), self::EXTRACTED_RESOURCE_GROUP_IDS, true);
        if (!$isKnown) {
            $errors[] = ['path' => $path.'.S', 'code' => 'unknown_product'];

            return null;
        }

        return $productTypeId;
    }

    /**
     * @param array<string, mixed> $layout
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<array<string, int|null>>|null
     */
    private function linksField(array $layout, ?int $pinCount, array &$errors): ?array
    {
        $links = $this->requiredList($layout, 'L', 'L', $errors);
        if ($links === null) {
            return null;
        }
        if (\count($links) > self::MAX_LINKS) {
            $errors[] = ['path' => 'L', 'code' => 'too_many_links'];

            return null;
        }

        $normalizedLinks = [];
        foreach ($links as $index => $link) {
            $path = 'L['.$index.']';
            if (!\is_array($link) || array_is_list($link)) {
                $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];
                $normalizedLinks[] = [];
                continue;
            }
            $source = $this->pinIndexField($link, 'S', $path.'.S', $pinCount, $errors);
            $destination = $this->pinIndexField($link, 'D', $path.'.D', $pinCount, $errors);
            $level = $this->integerField($link, 'Lv', $path.'.Lv', self::MIN_LINK_LEVEL, self::MAX_LINK_LEVEL, $errors);
            if ($source !== null && $source === $destination) {
                $errors[] = ['path' => $path.'.D', 'code' => 'same_pin'];
            }
            $normalizedLinks[] = ['D' => $destination, 'Lv' => $level, 'S' => $source];
        }

        return $normalizedLinks;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, InvType> $types
     * @param array<string, true>|null $linkedPairs
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<array<string, mixed>>|null
     */
    private function routesField(array $layout, array $types, ?int $pinCount, ?array $linkedPairs, array &$errors): ?array
    {
        $routes = $this->requiredList($layout, 'R', 'R', $errors);
        if ($routes === null) {
            return null;
        }
        if (\count($routes) > self::MAX_ROUTES) {
            $errors[] = ['path' => 'R', 'code' => 'too_many_routes'];

            return null;
        }

        $normalizedRoutes = [];
        foreach ($routes as $index => $route) {
            $path = 'R['.$index.']';
            if (!\is_array($route) || array_is_list($route)) {
                $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];
                $normalizedRoutes[] = [];
                continue;
            }
            $normalizedRoutes[] = [
                'P' => $this->routePathField($route, $path.'.P', $pinCount, $linkedPairs, $errors),
                'Q' => $this->integerField($route, 'Q', $path.'.Q', self::MIN_ROUTE_QUANTITY, self::MAX_ROUTE_QUANTITY, $errors),
                'T' => $this->routedCommodityField($route, $path.'.T', $types, $errors),
            ];
        }

        return $normalizedRoutes;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, true>|null $linkedPairs
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<int>|null
     */
    private function routePathField(array $route, string $path, ?int $pinCount, ?array $linkedPairs, array &$errors): ?array
    {
        $indexes = $this->requiredList($route, 'P', $path, $errors);
        if ($indexes === null) {
            return null;
        }
        if (\count($indexes) < self::MIN_ROUTE_PATH_LENGTH) {
            $errors[] = ['path' => $path, 'code' => 'too_short'];

            return null;
        }

        $pinIndexes = [];
        foreach ($indexes as $index) {
            if (!\is_int($index)) {
                $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];

                return null;
            }
            if ($pinCount !== null && ($index < 1 || $index > $pinCount)) {
                $errors[] = ['path' => $path, 'code' => self::CODE_OUT_OF_RANGE];

                return null;
            }
            $pinIndexes[] = $index;
        }

        if ($pinCount !== null && $linkedPairs !== null) {
            for ($position = 1; $position < \count($pinIndexes); ++$position) {
                if (!isset($linkedPairs[$this->linkKey($pinIndexes[$position - 1], $pinIndexes[$position])])) {
                    $errors[] = ['path' => $path, 'code' => 'route_not_linked'];

                    return $pinIndexes;
                }
            }
        }

        return $pinIndexes;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<int, InvType> $types
     * @param list<array{path: string, code: string}> $errors
     */
    private function routedCommodityField(array $route, string $path, array $types, array &$errors): ?int
    {
        $typeId = $this->requiredInteger($route, 'T', $path, $errors);
        if ($typeId === null) {
            return null;
        }
        if (!\in_array($this->groupIdOf($typeId, $types), self::ROUTED_COMMODITY_GROUP_IDS, true)) {
            $errors[] = ['path' => $path, 'code' => 'unknown_product'];

            return null;
        }

        return $typeId;
    }

    /**
     * @param array<string, mixed> $link
     * @param list<array{path: string, code: string}> $errors
     */
    private function pinIndexField(array $link, string $key, string $path, ?int $pinCount, array &$errors): ?int
    {
        return $this->integerField($link, $key, $path, 1, $pinCount ?? \PHP_INT_MAX, $errors);
    }

    /**
     * @param list<array<string, int|null>> $links
     *
     * @return array<string, true>
     */
    private function linkedPairs(array $links): array
    {
        $linkedPairs = [];
        foreach ($links as $link) {
            if (isset($link['S'], $link['D'])) {
                $linkedPairs[$this->linkKey($link['S'], $link['D'])] = true;
            }
        }

        return $linkedPairs;
    }

    private function linkKey(int $first, int $second): string
    {
        return min($first, $second).':'.max($first, $second);
    }

    /**
     * @return array<int, true>
     */
    private function schematicOutputTypeIds(): array
    {
        $outputTypeIds = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematic) {
            if ($schematic['output'] !== null) {
                $outputTypeIds[$schematic['output']['typeId']] = true;
            }
        }

        return $outputTypeIds;
    }

    /**
     * @param array<int, InvType> $types
     */
    private function groupIdOf(int $typeId, array $types): ?int
    {
        return isset($types[$typeId]) ? $types[$typeId]->getGroup()->getGroupId() : null;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<array{path: string, code: string}> $errors
     *
     * @return list<mixed>|null
     */
    private function requiredList(array $object, string $key, string $path, array &$errors): ?array
    {
        if (!\array_key_exists($key, $object)) {
            $errors[] = ['path' => $path, 'code' => self::CODE_MISSING];

            return null;
        }
        if (!\is_array($object[$key]) || !array_is_list($object[$key])) {
            $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];

            return null;
        }

        return $object[$key];
    }

    /**
     * @param array<string, mixed> $object
     * @param list<array{path: string, code: string}> $errors
     */
    private function requiredInteger(array $object, string $key, string $path, array &$errors): ?int
    {
        if (!\array_key_exists($key, $object)) {
            $errors[] = ['path' => $path, 'code' => self::CODE_MISSING];

            return null;
        }
        if (!\is_int($object[$key])) {
            $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];

            return null;
        }

        return $object[$key];
    }

    /**
     * @param array<string, mixed> $object
     * @param list<array{path: string, code: string}> $errors
     */
    private function integerField(array $object, string $key, string $path, int $minimum, int $maximum, array &$errors): ?int
    {
        $value = $this->requiredInteger($object, $key, $path, $errors);
        if ($value === null) {
            return null;
        }
        if ($value < $minimum || $value > $maximum) {
            $errors[] = ['path' => $path, 'code' => self::CODE_OUT_OF_RANGE];

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<array{path: string, code: string}> $errors
     */
    private function numberField(array $object, string $key, string $path, array &$errors): int|float|null
    {
        if (!\array_key_exists($key, $object)) {
            $errors[] = ['path' => $path, 'code' => self::CODE_MISSING];

            return null;
        }
        if (!\is_int($object[$key]) && !\is_float($object[$key])) {
            $errors[] = ['path' => $path, 'code' => self::CODE_INVALID_TYPE];

            return null;
        }

        return $object[$key];
    }
}
