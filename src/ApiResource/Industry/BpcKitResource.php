<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\BpcKitProvider;

#[ApiResource(
    shortName: 'IndustryBpcKit',
    description: 'BPC kit breakdown for an industry project (invention costs, decryptor comparison)',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{id}/bpc-kit',
            provider: BpcKitProvider::class,
            openapi: new Model\Operation(summary: 'Get BPC kit breakdown', description: 'Returns invention costs with all decryptor options and summary totals', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class BpcKitResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public bool $isT2 = false;

    /** @var list<array{productTypeId: int, productName: string, baseProbability: float, desiredSuccesses: int, datacores: array, decryptorOptions: array}> */
    public array $inventions = [];

    /** @var array{totalInventionCost: float, bestDecryptorTypeId: ?int, totalBpcKitCost: float} */
    public array $summary = [];
}
