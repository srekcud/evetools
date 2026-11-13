<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Assets\CharacterAssetsResource;
use App\ApiResource\Assets\CorporationAssetsResource;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class AssetListNormalizer implements NormalizerInterface
{
    private const array SUPPORTED_FORMATS = ['json', 'jsonld'];

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return \in_array($format, self::SUPPORTED_FORMATS, true)
            && ($data instanceof CorporationAssetsResource || $data instanceof CharacterAssetsResource);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            CorporationAssetsResource::class => true,
            CharacterAssetsResource::class => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CorporationAssetsResource || $data instanceof CharacterAssetsResource);

        $payload = get_object_vars($data);
        $payload['items'] = array_map(get_object_vars(...), $data->items);

        return $payload;
    }
}
