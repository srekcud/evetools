<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonCompositionRepository;
use Symfony\Component\Validator\ConstraintViolationInterface;

final readonly class MoonCompositionImporter
{
    private const string ESI_MOON_NAME_PATTERN = '/^(.+) ([IVXLCDM]+) - (Moon \d+)$/';

    public function __construct(
        private CorporationMiningExtractionRepository $extractionRepository,
        private MoonCompositionRepository $compositionRepository,
        private MoonCompositionSheetParser $sheetParser,
        private MoonCompositionRecorder $recorder,
    ) {
    }

    /**
     * @throws MoonScanException
     */
    public function import(string $csv): MoonCompositionImportReport
    {
        $compositionsByMoonName = $this->sheetParser->compositionsByMoonName($csv);

        $moons = $this->extractionRepository->findDistinctMoons();
        $matched = 0;
        $imported = 0;
        $skipped = 0;
        $notFound = 0;
        $rejected = [];
        foreach ($moons as $moon) {
            $moonName = $moon['moonName'];
            $composition = $moonName === null ? null : ($compositionsByMoonName[$this->toSheetMoonName($moonName)] ?? null);
            if ($moonName === null || $composition === null) {
                ++$notFound;
                continue;
            }
            ++$matched;

            if ($this->compositionRepository->findOneBy(['corporationId' => $moon['corporationId'], 'moonId' => $moon['moonId']]) !== null) {
                ++$skipped;
                continue;
            }

            try {
                $stored = $this->recorder->recordImported($moon['corporationId'], $moon['moonId'], $this->sheetParser->lines($composition));
            } catch (MoonScanException $rejection) {
                $rejected[] = ['moonName' => $moonName, 'reason' => $rejection->getMessage()];
                continue;
            } catch (ValidationException $rejection) {
                $rejected[] = ['moonName' => $moonName, 'reason' => implode('; ', array_map(static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(), iterator_to_array($rejection->getConstraintViolationList())))];
                continue;
            }

            $stored ? ++$imported : ++$skipped;
        }

        return new MoonCompositionImportReport(count($moons), $matched, $imported, $skipped, $notFound, $rejected);
    }

    private function toSheetMoonName(string $esiMoonName): string
    {
        return preg_replace(self::ESI_MOON_NAME_PATTERN, '$1 - $2 - $3', $esiMoonName) ?? $esiMoonName;
    }
}
