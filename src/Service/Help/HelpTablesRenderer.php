<?php

declare(strict_types=1);

namespace App\Service\Help;

final readonly class HelpTablesRenderer
{
    private const int SECONDS_PER_MINUTE = 60;
    private const int SECONDS_PER_HOUR = 3600;
    private const int SECONDS_PER_DAY = 86400;

    private const array TEXTS = [
        'fr' => [
            'scopesTitle' => 'Scopes ESI',
            'scopesColumns' => ['Scope', 'Usage'],
            'syncTitle' => 'Fréquences de synchronisation',
            'syncColumns' => ['Donnée', 'Fréquence'],
            'onDemand' => 'À la demande',
            'day' => 'jour',
            'days' => 'jours',
            'generated' => 'Ce tableau est généré depuis le code par `app:help:generate-tables` : ne le modifiez pas à la main.',
        ],
        'en' => [
            'scopesTitle' => 'ESI scopes',
            'scopesColumns' => ['Scope', 'Used for'],
            'syncTitle' => 'Sync frequencies',
            'syncColumns' => ['Data', 'Frequency'],
            'onDemand' => 'On demand',
            'day' => 'day',
            'days' => 'days',
            'generated' => 'This table is generated from the code by `app:help:generate-tables`: do not edit it by hand.',
        ],
    ];

    public function __construct(
        private HelpDescriptions $descriptions,
    ) {
    }

    /**
     * @param list<string>        $scopes
     * @param list<ScheduledSync> $scheduledSyncs
     *
     * @return array<string, string>
     */
    public function pages(array $scopes, array $scheduledSyncs): array
    {
        $pages = [];
        foreach (HelpDescriptions::LANGUAGES as $language) {
            $pages[$language.'/data/esi-scopes.md'] = $this->renderScopes($scopes, $language);
            $pages[$language.'/data/sync-frequencies.md'] = $this->renderSyncFrequencies($scheduledSyncs, $language);
        }

        return $pages;
    }

    /**
     * @param list<string> $scopes
     */
    public function renderScopes(array $scopes, string $language): string
    {
        $texts = $this->textsFor($language);
        $rows = array_map(
            fn (string $scope): array => ['`'.$scope.'`', $this->descriptions->scopeUsage($scope, $language)],
            $scopes,
        );

        return $this->renderPage($texts['scopesTitle'], $texts['generated'], $texts['scopesColumns'], $rows);
    }

    /**
     * @param list<ScheduledSync> $scheduledSyncs
     */
    public function renderSyncFrequencies(array $scheduledSyncs, string $language): string
    {
        $texts = $this->textsFor($language);

        $entries = array_map(fn (ScheduledSync $sync): array => [
            'interval' => $sync->intervalSeconds,
            'label' => $this->descriptions->syncLabel($sync->messageClass, $language),
        ], $scheduledSyncs);
        usort($entries, static fn (array $left, array $right): int => [$left['interval'], $left['label']] <=> [$right['interval'], $right['label']]);

        $rows = array_map(fn (array $entry): array => [$entry['label'], $this->formatInterval($entry['interval'], $language)], $entries);
        foreach ($this->descriptions->onDemandSyncLabels($language) as $label) {
            $rows[] = [$label, $texts['onDemand']];
        }

        return $this->renderPage($texts['syncTitle'], $texts['generated'], $texts['syncColumns'], $rows);
    }

    private function formatInterval(int $seconds, string $language): string
    {
        $texts = $this->textsFor($language);

        if (0 === $seconds % self::SECONDS_PER_DAY) {
            $days = intdiv($seconds, self::SECONDS_PER_DAY);

            return $days.' '.(1 === $days ? $texts['day'] : $texts['days']);
        }
        if (0 === $seconds % self::SECONDS_PER_HOUR) {
            return intdiv($seconds, self::SECONDS_PER_HOUR).' h';
        }
        if (0 === $seconds % self::SECONDS_PER_MINUTE) {
            return intdiv($seconds, self::SECONDS_PER_MINUTE).' min';
        }

        throw new \InvalidArgumentException(\sprintf('Interval of %d seconds is not a whole number of minutes', $seconds));
    }

    /**
     * @param array{0: string, 1: string}  $columns
     * @param list<array{0: string, 1: string}> $rows
     */
    private function renderPage(string $title, string $introduction, array $columns, array $rows): string
    {
        $lines = ['# '.$title, '', $introduction, '', $this->renderRow($columns), '| --- | --- |'];
        foreach ($rows as $row) {
            $lines[] = $this->renderRow($row);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array{0: string, 1: string} $cells
     */
    private function renderRow(array $cells): string
    {
        foreach ($cells as $cell) {
            if (str_contains($cell, "\n")) {
                throw new \InvalidArgumentException(\sprintf('Help table cell "%s" contains a line break', $cell));
            }
        }

        return '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\|', $cell), $cells)).' |';
    }

    /**
     * @return array<string, mixed>
     */
    private function textsFor(string $language): array
    {
        return self::TEXTS[$language] ?? throw new \InvalidArgumentException(\sprintf('Unsupported help language "%s"', $language));
    }
}
