<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Help;

use App\Service\Help\HelpDescriptions;
use App\Service\Help\HelpTablesRenderer;
use App\Service\Help\ScheduledSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HelpTablesRenderer::class)]
final class HelpTablesRendererTest extends TestCase
{
    private const SCOPES = ['scope-a', 'scope-b'];

    /** @return iterable<string, array{int, string, string}> */
    public static function intervals(): iterable
    {
        yield '5 minutes fr' => [300, 'fr', '5 min'];
        yield '30 minutes en' => [1800, 'en', '30 min'];
        yield '1 hour' => [3600, 'fr', '1 h'];
        yield '2 hours' => [7200, 'en', '2 h'];
        yield '12 hours' => [43200, 'fr', '12 h'];
        yield '1 day fr' => [86400, 'fr', '1 jour'];
        yield '1 day en' => [86400, 'en', '1 day'];
        yield '2 days fr' => [172800, 'fr', '2 jours'];
        yield '2 days en' => [172800, 'en', '2 days'];
    }

    #[DataProvider('intervals')]
    public function testItFormatsTheIntervalOfEachScheduledSync(int $seconds, string $language, string $expected): void
    {
        $table = $this->renderer()->renderSyncFrequencies([new ScheduledSync('task-a', $seconds)], $language);

        self::assertStringContainsString(\sprintf("| Task A %s | %s |\n", $language, $expected), $table);
    }

    public function testAnIntervalThatIsNotWholeMinutesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->renderer()->renderSyncFrequencies([new ScheduledSync('task-a', 90)], 'fr');
    }

    public function testTheScopesTableInEnglish(): void
    {
        $table = $this->renderer()->renderScopes(self::SCOPES, 'en');

        self::assertStringStartsWith("# ESI scopes\n\n", $table);
        self::assertStringContainsString('app:help:generate-tables', $table);
        self::assertStringContainsString(
            "\n\n| Scope | Used for |\n| --- | --- |\n| `scope-a` | Usage A en |\n| `scope-b` | Usage B en |\n",
            $table,
        );
        self::assertStringEndsWith("|\n", $table);
    }

    public function testTheScopesTableInFrench(): void
    {
        $table = $this->renderer()->renderScopes(self::SCOPES, 'fr');

        self::assertStringStartsWith("# Scopes ESI\n\n", $table);
        self::assertStringContainsString(
            "\n\n| Scope | Usage |\n| --- | --- |\n| `scope-a` | Usage A fr |\n| `scope-b` | Usage B fr |\n",
            $table,
        );
    }

    public function testTheScopesKeepTheOrderOfTheCode(): void
    {
        $table = $this->renderer()->renderScopes(['scope-b', 'scope-a'], 'en');

        self::assertLessThan(strpos($table, '`scope-a`'), strpos($table, '`scope-b`'));
    }

    public function testTheSyncTableTitlesAndHeaders(): void
    {
        $french = $this->renderer()->renderSyncFrequencies([], 'fr');
        $english = $this->renderer()->renderSyncFrequencies([], 'en');

        self::assertStringStartsWith("# Fréquences de synchronisation\n\n", $french);
        self::assertStringContainsString("| Donnée | Fréquence |\n| --- | --- |\n", $french);
        self::assertStringStartsWith("# Sync frequencies\n\n", $english);
        self::assertStringContainsString("| Data | Frequency |\n| --- | --- |\n", $english);
    }

    public function testSyncsAreSortedByIntervalThenLabelAndOnDemandSyncsComeLast(): void
    {
        $table = $this->renderer()->renderSyncFrequencies([
            new ScheduledSync('task-b', 86400),
            new ScheduledSync('task-a', 300),
            new ScheduledSync('task-c', 300),
        ], 'en');

        $positions = array_map(static fn (string $label): int|false => strpos($table, $label), [
            'Task A en', 'Task C en', 'Task B en', 'Assets en',
        ]);

        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        self::assertStringContainsString('| Assets en | On demand |', $table);
    }

    public function testOnDemandRowsUseTheFrenchFrequencyLabel(): void
    {
        $table = $this->renderer()->renderSyncFrequencies([], 'fr');

        self::assertStringContainsString('| Assets fr | À la demande |', $table);
    }

    public function testAPipeInADescriptionIsEscaped(): void
    {
        $renderer = new HelpTablesRenderer(new HelpDescriptions(
            ['scope-a' => ['fr' => 'a | b', 'en' => 'a | b']],
            [],
            [],
        ));

        self::assertStringContainsString('| `scope-a` | a \| b |', $renderer->renderScopes(['scope-a'], 'en'));
    }

    public function testADescriptionWithALineBreakIsRejected(): void
    {
        $renderer = new HelpTablesRenderer(new HelpDescriptions(
            ['scope-a' => ['fr' => "a\nb", 'en' => "a\nb"]],
            [],
            [],
        ));

        $this->expectException(\InvalidArgumentException::class);

        $renderer->renderScopes(['scope-a'], 'en');
    }

    public function testNoScopesGivesTheHeaderOnly(): void
    {
        $table = $this->renderer()->renderScopes([], 'en');

        self::assertStringEndsWith("| Scope | Used for |\n| --- | --- |\n", $table);
    }

    public function testPagesAreKeyedByRelativePathInBothLanguagesAndDeterministic(): void
    {
        $pages = $this->renderer()->pages(self::SCOPES, [new ScheduledSync('task-a', 300)]);

        self::assertSame(
            ['fr/data/esi-scopes.md', 'fr/data/sync-frequencies.md', 'en/data/esi-scopes.md', 'en/data/sync-frequencies.md'],
            array_keys($pages),
        );
        self::assertSame($pages, $this->renderer()->pages(self::SCOPES, [new ScheduledSync('task-a', 300)]));
    }

    private function renderer(): HelpTablesRenderer
    {
        return new HelpTablesRenderer(new HelpDescriptions(
            [
                'scope-a' => ['fr' => 'Usage A fr', 'en' => 'Usage A en'],
                'scope-b' => ['fr' => 'Usage B fr', 'en' => 'Usage B en'],
            ],
            [
                'task-a' => ['fr' => 'Task A fr', 'en' => 'Task A en'],
                'task-b' => ['fr' => 'Task B fr', 'en' => 'Task B en'],
                'task-c' => ['fr' => 'Task C fr', 'en' => 'Task C en'],
            ],
            [
                ['fr' => 'Assets fr', 'en' => 'Assets en'],
            ],
        ));
    }
}
