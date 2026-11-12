<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\Sde\IndustryActivityProductRepository;
use PHPUnit\Framework\MockObject\Stub;

final class ProducerOfStub
{
    public static function from(IndustryActivityProductRepository&Stub $repository): void
    {
        $repository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => $repository->findBlueprintForProduct($productTypeId, IndustryActivityType::Manufacturing->value)
                ?? $repository->findBlueprintForProduct($productTypeId, IndustryActivityType::Reaction->value)
                ?? throw new ProducerMissingFromSdeException($productTypeId),
        );
    }
}
