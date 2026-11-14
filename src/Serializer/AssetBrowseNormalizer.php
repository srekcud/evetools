<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Assets\AssetBrowseResource;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class AssetBrowseNormalizer implements NormalizerInterface
{
    private const array SUPPORTED_FORMATS = ['json', 'jsonld'];

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return \in_array($format, self::SUPPORTED_FORMATS, true) && $data instanceof AssetBrowseResource;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [AssetBrowseResource::class => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof AssetBrowseResource);

        return self::toArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(object $object): array
    {
        return array_map(self::normalizeValue(...), get_object_vars($object));
    }

    private static function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            \is_object($value) => self::toArray($value),
            \is_array($value) => array_map(self::normalizeValue(...), $value),
            default => $value,
        };
    }
}
