<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Industry;

use App\ApiResource\Industry\ProfitMarginResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfitMarginResource::class)]
#[Group('issue-217')]
final class ProfitMarginResourceTest extends TestCase
{
    public function testContractCountIsUnknownByDefault(): void
    {
        $resource = new ProfitMarginResource();

        $this->assertNull($resource->sellPrices['contractCount']);
        $this->assertNull($resource->sellPrices['contractSell']);
    }
}
