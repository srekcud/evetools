<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Esi;

use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Esi\EsiSystemCostIndices;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Adapter of the SystemCostIndices port (spec R6, R10) over the cost indices cached by EsiCostIndexService.
 *
 * The real EsiCostIndexService fills an in-memory cache from GET /industry/systems/, simulated at EsiClient::get().
 * ESI activity keys: manufacturing, reaction, copying, invention, researching_time_efficiency,
 * researching_material_efficiency (EsiCostIndexService::getCostIndex()).
 */
final class EsiSystemCostIndicesTest extends TestCase
{
    private const int JITA = 30000142;
    private const int PERIMETER = 30000144;
    private const int SYSTEM_UNKNOWN_TO_THE_ESI = 30000145;

    private EsiCostIndexService $esiCostIndexService;

    protected function setUp(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnMap([
            ['/industry/systems/', null, [], [
                [
                    'solar_system_id' => self::JITA,
                    'cost_indices' => [
                        ['activity' => 'manufacturing', 'cost_index' => 0.0712],
                        ['activity' => 'researching_time_efficiency', 'cost_index' => 0.0911],
                        ['activity' => 'researching_material_efficiency', 'cost_index' => 0.0922],
                        ['activity' => 'copying', 'cost_index' => 0.0215],
                        ['activity' => 'invention', 'cost_index' => 0.0634],
                        ['activity' => 'reaction', 'cost_index' => 0.0343],
                    ],
                ],
                [
                    'solar_system_id' => self::PERIMETER,
                    'cost_indices' => [
                        ['activity' => 'manufacturing', 'cost_index' => 0.0456],
                    ],
                ],
            ]],
        ]);

        $this->esiCostIndexService = new EsiCostIndexService($esiClient, new ArrayAdapter(), new NullLogger());
    }

    /**
     * @return iterable<string, array{ActivityKind, float}>
     */
    public static function costIndexOfJitaByActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing, 0.0712];
        yield 'reaction' => [ActivityKind::Reaction, 0.0343];
        yield 'copying' => [ActivityKind::Copying, 0.0215];
        yield 'invention' => [ActivityKind::Invention, 0.0634];
    }

    #[DataProvider('costIndexOfJitaByActivityProvider')]
    public function testCostIndexIsTheOnePublishedByTheEsiForTheSystemAndTheActivity(ActivityKind $activity, float $expectedCostIndex): void
    {
        $this->esiCostIndexService->syncCostIndices();

        $this->assertSame($expectedCostIndex, $this->costIndices()->costIndexOf(self::JITA, $activity));
    }

    public function testCostIndexOfAnotherSystemIsItsOwn(): void
    {
        $this->esiCostIndexService->syncCostIndices();

        $this->assertSame(0.0456, $this->costIndices()->costIndexOf(self::PERIMETER, ActivityKind::Manufacturing));
    }

    public function testSystemUnknownToTheEsiHasNoCostIndex(): void
    {
        $this->esiCostIndexService->syncCostIndices();

        $this->assertNull($this->costIndices()->costIndexOf(self::SYSTEM_UNKNOWN_TO_THE_ESI, ActivityKind::Manufacturing));
    }

    public function testActivityWithoutIndexInTheSystemHasNoCostIndex(): void
    {
        $this->esiCostIndexService->syncCostIndices();

        $this->assertNull($this->costIndices()->costIndexOf(self::PERIMETER, ActivityKind::Reaction));
        $this->assertNull($this->costIndices()->costIndexOf(self::PERIMETER, ActivityKind::Invention));
    }

    public function testNoCostIndexBeforeTheFirstSync(): void
    {
        $this->assertNull($this->costIndices()->costIndexOf(self::JITA, ActivityKind::Manufacturing));
    }

    private function costIndices(): EsiSystemCostIndices
    {
        return new EsiSystemCostIndices($this->esiCostIndexService);
    }
}
