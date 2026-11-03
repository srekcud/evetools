<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningValuationForm::class)]
#[Group('issue-264')]
final class MiningValuationFormTest extends TestCase
{
    public function testEachFormReadsOnePriceKeyOfTheOreValueServiceOnEachMarket(): void
    {
        $priceKeys = [];
        foreach (MiningValuationForm::cases() as $form) {
            foreach (MiningValuationMarket::cases() as $market) {
                $priceKeys[$form->value][$market->value] = $form->priceKeyOn($market);
            }
        }

        self::assertSame(
            [
                'raw' => ['jita' => 'rawUnitPrice', 'structure' => 'structureUnitPrice'],
                'compressed' => ['jita' => 'compressedEquivalentPrice', 'structure' => 'structureCompressedEquivalentPrice'],
                'reprocessed' => ['jita' => 'reprocessValue', 'structure' => 'structureReprocessValue'],
            ],
            $priceKeys,
        );
    }
}
