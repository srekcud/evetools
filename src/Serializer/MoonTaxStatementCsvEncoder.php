<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

#[AutoconfigureTag('serializer.encoder', ['priority' => 100])]
final readonly class MoonTaxStatementCsvEncoder implements EncoderInterface
{
    private const string FORMAT = 'csv';
    private const string LINE_BREAK = "\r\n";
    private const string FORMULA_PREFIXES = '=+-@';

    /**
     * @param array<string, mixed> $context
     */
    public function supportsEncoding(string $format, array $context = []): bool
    {
        return $format === self::FORMAT;
    }

    /**
     * @param list<array<string, scalar|null>> $data
     */
    public function encode(mixed $data, string $format, array $context = []): string
    {
        if ($data === []) {
            return '';
        }

        $lines = [$this->line(array_keys($data[0]))];
        foreach ($data as $row) {
            $lines[] = $this->line(array_values(array_map($this->cell(...), $row)));
        }

        return implode(self::LINE_BREAK, $lines) . self::LINE_BREAK;
    }

    /**
     * @param list<string> $cells
     */
    private function line(array $cells): string
    {
        return implode(',', array_map($this->quoted(...), $cells));
    }

    private function cell(bool|int|float|string|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_string($value) && $value !== '' && str_contains(self::FORMULA_PREFIXES, $value[0]) => "'" . $value,
            default => (string) $value,
        };
    }

    private function quoted(string $cell): string
    {
        if (strpbrk($cell, ",\"\r\n") === false) {
            return $cell;
        }

        return '"' . str_replace('"', '""', $cell) . '"';
    }
}
