<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Les admins sont identifiés par eveCharacterId via ADMIN_CHARACTER_IDS, dans App\Security\AdminChecker uniquement.
 * Aucun nom de personnage admin ne doit subsister dans le code ou la configuration.
 */
class AdminByCharacterIdTest extends TestCase
{
    private const PROJECT_DIR = __DIR__.'/../../..';
    private const FORBIDDEN_REFERENCES = ['admin_character_names', 'adminCharacterNames'];

    public function testNoFileInSrcOrConfigReferencesAdminCharacterNames(): void
    {
        $offendingFiles = [];
        foreach (['src', 'config'] as $directory) {
            foreach ($this->filesIn(self::PROJECT_DIR.'/'.$directory) as $path) {
                $content = (string) file_get_contents($path);
                foreach (self::FORBIDDEN_REFERENCES as $reference) {
                    if (str_contains($content, $reference)) {
                        $offendingFiles[] = $directory.substr($path, strlen(self::PROJECT_DIR.'/'.$directory));
                        break;
                    }
                }
            }
        }

        sort($offendingFiles);
        $this->assertSame([], $offendingFiles);
    }

    public function testAdminCheckerIsTheOnlyAdminCheckImplementationInSrc(): void
    {
        $filesReadingAdminCharacterIds = [];
        foreach ($this->filesIn(self::PROJECT_DIR.'/src') as $path) {
            if (str_contains((string) file_get_contents($path), 'adminCharacterIds')) {
                $filesReadingAdminCharacterIds[] = 'src'.substr($path, strlen(self::PROJECT_DIR.'/src'));
            }
        }

        $this->assertSame(['src/Security/AdminChecker.php'], $filesReadingAdminCharacterIds);
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
