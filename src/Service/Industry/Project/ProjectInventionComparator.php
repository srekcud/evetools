<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Industry\Application\InventionCatalog;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\AnalysisSolarSystems;
use App\Service\Industry\ProfitMarginService;

final readonly class ProjectInventionComparator
{
    private const string UNUSED_OPTION_FIELD = 'bestMargin';

    public function __construct(
        private ProfitMarginService $profitMargin,
        private InventionCatalog $inventions,
        private IndustryUserSettingsRepository $settings,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function compare(IndustryProject $project, ?Character $inventor): array
    {
        $owner = $project->getOwner();
        $solarSystems = AnalysisSolarSystems::resolve(null, $this->settings->findOneBy(['user' => $owner]));
        $sellStructureId = $owner->getEffectiveMarketStructureId() ?? EveConstants::JITA_STATION_ID;

        $entries = [];
        foreach ($project->getItems() as $product) {
            if (null === $this->inventions->inventionOf($product->getTypeId())) {
                continue;
            }

            $analysis = $this->profitMargin->analyze(
                $product->getTypeId(),
                $product->getRuns(),
                $product->getMeLevel(),
                $product->getTeLevel(),
                $sellStructureId,
                $solarSystems->manufacturingSolarSystemId,
                null,
                null,
                $owner,
                $solarSystems->reactionSolarSystemId,
                $inventor,
            );
            $entries[] = $this->entryOf($product, $analysis['invention'] ?? throw new \LogicException('An invented product has an invention analysis.'));
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $invention
     *
     * @return array<string, mixed>
     */
    private function entryOf(IndustryProjectProduct $product, array $invention): array
    {
        return [
            'typeId' => $product->getTypeId(),
            'typeName' => $product->getTypeName(),
            'runs' => $product->getRuns(),
            'me' => $product->getMeLevel(),
            'te' => $product->getTeLevel(),
            'invention' => [
                'baseProbability' => $invention['baseProbability'],
                'unknownReason' => $invention['unknownReason'],
                'datacores' => $invention['datacores'],
                'datacoresCostPerAttempt' => $invention['datacoresCostPerAttempt'],
                'inventor' => $invention['inventor'],
                'options' => array_map(
                    static fn (array $option): array => array_diff_key($option, [self::UNUSED_OPTION_FIELD => true]),
                    $invention['options'],
                ),
            ],
        ];
    }
}
