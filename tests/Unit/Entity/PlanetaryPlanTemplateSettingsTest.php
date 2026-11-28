<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlanetaryPlanTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPlanTemplate::class)]
#[Group('issue-1454')]
final class PlanetaryPlanTemplateSettingsTest extends TestCase
{
    public function testANewTemplateDefaultsToTheJitaSellPriceForPurchasesAndSales(): void
    {
        $settings = (new PlanetaryPlanTemplate())->getSettings();

        $this->assertSame(['purchaseSource' => 'jita-sell', 'saleSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4], $settings);
    }

    public function testTheSettingsOfTheTwoSourcesAreReadBackAsStored(): void
    {
        $template = (new PlanetaryPlanTemplate())->setSettings(['purchaseSource' => 'best', 'saleSource' => 'hub', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6]);

        $this->assertSame(['purchaseSource' => 'best', 'saleSource' => 'hub', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], $template->getSettings());
    }

    public function testATemplateStoredWithTheFormerSingleSourceReadsWithThatSourceForPurchasesAndSales(): void
    {
        $template = new PlanetaryPlanTemplate();
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'settings'))->setValue($template, ['priceSource' => 'jita-buy', 'taxPercent' => 10, 'daysBetweenRounds' => 3]);

        $this->assertSame(['purchaseSource' => 'jita-buy', 'saleSource' => 'jita-buy', 'taxPercent' => 10, 'daysBetweenRounds' => 3], $template->getSettings());
    }
}
