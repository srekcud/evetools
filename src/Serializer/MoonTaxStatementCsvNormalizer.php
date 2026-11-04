<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\MoonTax\MoonTaxStatementResource;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class MoonTaxStatementCsvNormalizer implements NormalizerInterface
{
    private const string FORMAT = 'csv';
    private const string UNKNOWN_TYPE_IDS_SEPARATOR = ';';

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $format === self::FORMAT && $data instanceof MoonTaxStatementResource;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [MoonTaxStatementResource::class => false];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MoonTaxStatementResource);

        return [
            'reference' => $data->reference,
            'characterId' => $data->characterId,
            'characterName' => $data->characterName,
            'structureId' => $data->structureId,
            'periodStart' => $data->periodStart->format(\DateTimeInterface::ATOM),
            'periodEnd' => $data->periodEnd->format(\DateTimeInterface::ATOM),
            'tax' => $data->tax,
            'paid' => $data->paid,
            'remaining' => $data->remaining,
            'status' => $data->status,
            'outsideAlliance' => $data->outsideAlliance,
            'unknownTypeIds' => implode(self::UNKNOWN_TYPE_IDS_SEPARATOR, $data->unknownTypeIds),
        ];
    }
}
