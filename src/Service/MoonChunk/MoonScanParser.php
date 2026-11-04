<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonScanParser
{
    public const float MIN_SHARES_SUM = 0.99;
    public const float MAX_SHARES_SUM = 1.01;

    private const string LINE_PATTERN = '/^(?<name>.+?)(?: *\t[ \t]*| {2,})(?<share>\S.*)$/u';
    private const string SHARE_PATTERN = '/^(?<number>-?\d+(?:[.,]\d+)?)\s*(?<percent>%)?$/';
    private const int SHARE_PRECISION = 9;

    /**
     * @return list<MoonScanLine>
     *
     * @throws MoonScanException
     */
    public function parse(string $scan): array
    {
        $lines = [];
        $lineNumbersByLowerName = [];
        foreach (preg_split('/\R/u', $scan) ?: [] as $index => $rawLine) {
            $lineNumber = $index + 1;
            $text = trim($rawLine);
            if ($text === '') {
                continue;
            }

            $line = $this->lineOf($text, $lineNumber);
            $lowerName = mb_strtolower($line->name);
            if (isset($lineNumbersByLowerName[$lowerName])) {
                throw new MoonScanException(sprintf('Scan rejected at line %d: %s is already listed at line %d', $lineNumber, $line->name, $lineNumbersByLowerName[$lowerName]), $lineNumber);
            }
            $lineNumbersByLowerName[$lowerName] = $lineNumber;
            $lines[] = $line;
        }

        $this->assertSharesAddUpToOne($lines);

        return $lines;
    }

    private function lineOf(string $text, int $lineNumber): MoonScanLine
    {
        if (preg_match(self::LINE_PATTERN, $text, $parts) !== 1) {
            throw new MoonScanException(sprintf('Scan rejected at line %d: no share after the ore name', $lineNumber), $lineNumber);
        }

        return new MoonScanLine(trim($parts['name']), $this->shareOf($parts['share'], $lineNumber), $lineNumber);
    }

    private function shareOf(string $text, int $lineNumber): float
    {
        if (preg_match(self::SHARE_PATTERN, trim($text), $parts) !== 1) {
            throw new MoonScanException(sprintf('Scan rejected at line %d: "%s" is not a share', $lineNumber, $text), $lineNumber);
        }

        $number = (float) str_replace(',', '.', $parts['number']);
        $share = round(isset($parts['percent']) ? $number / 100 : $number, self::SHARE_PRECISION);
        if ($share <= 0.0 || $share > 1.0) {
            throw new MoonScanException(sprintf('Scan rejected at line %d: the share must be above 0 and at most 100 %%', $lineNumber), $lineNumber);
        }

        return $share;
    }

    /**
     * @param list<MoonScanLine> $lines
     */
    private function assertSharesAddUpToOne(array $lines): void
    {
        if ($lines === []) {
            throw new MoonScanException('Scan rejected: it lists no ore');
        }

        $sum = round(array_sum(array_map(static fn (MoonScanLine $line): float => $line->share, $lines)), self::SHARE_PRECISION);
        if ($sum < self::MIN_SHARES_SUM || $sum > self::MAX_SHARES_SUM) {
            throw new MoonScanException(sprintf('Scan rejected: the shares add up to %s %%, expected 100 %%', rtrim(rtrim(number_format($sum * 100, 2, '.', ''), '0'), '.')));
        }
    }
}
