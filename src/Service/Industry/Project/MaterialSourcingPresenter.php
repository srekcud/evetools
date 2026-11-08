<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\MaterialSourcing;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Service\Industry\MissingDataPresenter;
use App\Service\Industry\PartialPricePresenter;

final class MaterialSourcingPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    /**
     * @return array<string, mixed>
     */
    public static function rowOf(MaterialSourcing $sourcing, ?string $typeName): array
    {
        $jitaWithImportCost = $sourcing->jitaWithImportCost();

        return [
            'typeId' => $sourcing->typeId,
            'typeName' => $typeName,
            'quantity' => $sourcing->quantity->value,
            'volumePerUnit' => $sourcing->volumePerUnit,
            'jumps' => $sourcing->jumps,
            'jitaTotal' => self::rounded($sourcing->jitaCost),
            'importTotal' => self::rounded($sourcing->importCost),
            'jitaWithImportTotal' => self::rounded($jitaWithImportCost),
            'structureTotal' => self::rounded($sourcing->structureCost),
            'bestTotal' => self::rounded($sourcing->bestCost()),
            'side' => $sourcing->side->value,
            'structureFallback' => $sourcing->structureFallback,
            'saving' => self::roundedIsk($sourcing->saving),
            'missingData' => MissingDataPresenter::of($jitaWithImportCost),
            'warnings' => PartialPricePresenter::of($jitaWithImportCost),
        ];
    }

    /**
     * @param list<MaterialSourcing> $sourcings
     *
     * @return array{jitaWithImport: ?float, structure: ?float, best: ?float}
     */
    public static function totalsOf(array $sourcings): array
    {
        $nothing = Cost::known(new Isk(0.0));
        $jitaWithImport = $nothing;
        $structure = $nothing;
        $best = $nothing;
        foreach ($sourcings as $sourcing) {
            $jitaWithImport = $jitaWithImport->plus($sourcing->jitaWithImportCost());
            $structure = $structure->plus($sourcing->structureCost);
            $best = $best->plus($sourcing->bestCost());
        }

        return [
            'jitaWithImport' => self::rounded($jitaWithImport),
            'structure' => self::rounded($structure),
            'best' => self::rounded($best),
        ];
    }

    private static function rounded(Cost $cost): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount, self::DISPLAYED_DECIMALS) : null;
    }

    private static function roundedIsk(?Isk $amount): ?float
    {
        return null === $amount ? null : round($amount->amount, self::DISPLAYED_DECIMALS);
    }
}
