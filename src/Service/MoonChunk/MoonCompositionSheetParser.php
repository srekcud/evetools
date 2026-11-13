<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonCompositionSheetParser
{
    private const string MOON_NAME_COLUMN = 'Moon Name';
    private const string ORE_COMPOSITION_COLUMN = 'Ore Composition';
    private const string ORE_SEPARATOR = ';';
    private const string ORE_PATTERN = '/^(?<name>.+?)\s*:\s*(?<share>\S+)$/u';
    private const string UTF8_BOM = "\xEF\xBB\xBF";
    private const int SHARE_PRECISION = 9;

    /**
     * @return array<string, string>
     *
     * @throws MoonScanException
     */
    public function compositionsByMoonName(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open an in-memory stream to read the sheet');
        }
        fwrite($stream, $csv);
        rewind($stream);

        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            $moonNameIndex = $header === false ? false : array_search(self::MOON_NAME_COLUMN, array_map($this->headerCell(...), $header), true);
            $compositionIndex = $header === false ? false : array_search(self::ORE_COMPOSITION_COLUMN, array_map($this->headerCell(...), $header), true);
            if ($moonNameIndex === false || $compositionIndex === false) {
                throw new MoonScanException(sprintf('The sheet needs the columns "%s" and "%s"', self::MOON_NAME_COLUMN, self::ORE_COMPOSITION_COLUMN));
            }

            $compositions = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $moonName = trim((string) ($row[$moonNameIndex] ?? ''));
                if ($moonName === '' || !isset($row[$compositionIndex]) || isset($compositions[$moonName])) {
                    continue;
                }
                $compositions[$moonName] = $row[$compositionIndex];
            }

            return $compositions;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return list<MoonScanLine>
     *
     * @throws MoonScanException
     */
    public function lines(string $composition): array
    {
        if (trim($composition) === '') {
            throw new MoonScanException('Composition rejected: it lists no ore');
        }

        $lines = [];
        $lineNumbersByLowerName = [];
        foreach (explode(self::ORE_SEPARATOR, $composition) as $index => $segment) {
            $lineNumber = $index + 1;
            $line = $this->lineOf(trim($segment), $lineNumber);
            $lowerName = mb_strtolower($line->name);
            if (isset($lineNumbersByLowerName[$lowerName])) {
                throw new MoonScanException(sprintf('Composition rejected at ore %d: %s is already listed at ore %d', $lineNumber, $line->name, $lineNumbersByLowerName[$lowerName]), $lineNumber);
            }
            $lineNumbersByLowerName[$lowerName] = $lineNumber;
            $lines[] = $line;
        }

        $this->assertSharesDoNotExceedOne($lines);

        return $lines;
    }

    private function headerCell(?string $cell): string
    {
        return trim(str_replace(self::UTF8_BOM, '', (string) $cell));
    }

    private function lineOf(string $segment, int $lineNumber): MoonScanLine
    {
        if (preg_match(self::ORE_PATTERN, $segment, $parts) !== 1) {
            throw new MoonScanException(sprintf('Composition rejected at ore %d: "%s" is not "<ore>: <share>"', $lineNumber, $segment), $lineNumber);
        }
        if (!is_numeric($parts['share'])) {
            throw new MoonScanException(sprintf('Composition rejected at ore %d: "%s" is not a share', $lineNumber, $parts['share']), $lineNumber);
        }

        $share = round((float) $parts['share'], self::SHARE_PRECISION);
        if ($share <= 0.0 || $share > 1.0) {
            throw new MoonScanException(sprintf('Composition rejected at ore %d: the share must be above 0 and at most 1', $lineNumber), $lineNumber);
        }

        return new MoonScanLine(trim($parts['name']), $share, $lineNumber);
    }

    /**
     * @param list<MoonScanLine> $lines
     */
    private function assertSharesDoNotExceedOne(array $lines): void
    {
        $sum = round(array_sum(array_map(static fn (MoonScanLine $line): float => $line->share, $lines)), self::SHARE_PRECISION);
        if ($sum > MoonScanParser::MAX_SHARES_SUM) {
            throw new MoonScanException(sprintf('Composition rejected: the shares add up to %s %%, above 100 %%', rtrim(rtrim(number_format($sum * 100, 2, '.', ''), '0'), '.')));
        }
    }
}
