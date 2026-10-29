<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-200')]
final class IndustryUriIdentifiersTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__.'/../../..';
    private const array INDUSTRY_STATE_DIRECTORIES = ['src/State/Processor/Industry', 'src/State/Provider/Industry'];
    private const string UNGUARDED_URI_IDENTIFIER_PARSING = 'Uuid::fromString($uriVariables';

    public function testNoIndustryProcessorNorProviderParsesAnUriIdentifierWithoutTheNotFoundGuard(): void
    {
        $offendingFiles = array_values(array_filter(
            $this->industryStateFiles(),
            fn (string $file): bool => str_contains((string) file_get_contents(self::PROJECT_DIR.'/'.$file), self::UNGUARDED_URI_IDENTIFIER_PARSING),
        ));

        self::assertSame([], $offendingFiles);
    }

    public function testTheScanReadsTheIndustryProcessorsAndProviders(): void
    {
        $industryStateFiles = $this->industryStateFiles();

        self::assertContains('src/State/Processor/Industry/LinkJobProcessor.php', $industryStateFiles);
        self::assertContains('src/State/Provider/Industry/StepPurchaseDeleteProvider.php', $industryStateFiles);
    }

    /**
     * @return list<string>
     */
    private function industryStateFiles(): array
    {
        $files = [];
        foreach (self::INDUSTRY_STATE_DIRECTORIES as $directory) {
            foreach (glob(self::PROJECT_DIR.'/'.$directory.'/*.php') ?: [] as $path) {
                $files[] = $directory.'/'.basename($path);
            }
        }
        sort($files);

        return $files;
    }
}
