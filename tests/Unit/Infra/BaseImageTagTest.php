<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infra;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[Group('issue-614')]
final class BaseImageTagTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/base-image-tag';
    private const string DOCKERFILE_CONTENT = "FROM dunglas/frankenphp:1.13-php8.5-alpine\nRUN install-php-extensions intl\n";

    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/base-image-tag-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->workDirectory . '/*') ?: []);
        rmdir($this->workDirectory);
    }

    public function testTheTagIsTheDockerfileHashFollowedByTheMonth(): void
    {
        $dockerfile = $this->dockerfile(self::DOCKERFILE_CONTENT);

        $tag = $this->tagOf($dockerfile, '202611');

        self::assertSame(substr(hash('sha256', self::DOCKERFILE_CONTENT), 0, 12) . '-202611', $tag);
    }

    public function testTheTagStaysTheSameWhenNothingChangesDuringTheMonth(): void
    {
        $dockerfile = $this->dockerfile(self::DOCKERFILE_CONTENT);

        self::assertSame($this->tagOf($dockerfile, '202611'), $this->tagOf($dockerfile, '202611'));
    }

    public function testTheTagChangesWhenTheDockerfileChanges(): void
    {
        $original = $this->dockerfile(self::DOCKERFILE_CONTENT);
        $edited = $this->dockerfile(self::DOCKERFILE_CONTENT . "RUN apk add --no-cache git\n", 'edited');

        self::assertNotSame($this->tagOf($original, '202611'), $this->tagOf($edited, '202611'));
    }

    public function testTheTagChangesWhenTheMonthChanges(): void
    {
        $dockerfile = $this->dockerfile(self::DOCKERFILE_CONTENT);

        self::assertNotSame($this->tagOf($dockerfile, '202611'), $this->tagOf($dockerfile, '202612'));
    }

    public function testTheTagUsesTheCurrentMonthByDefault(): void
    {
        $dockerfile = $this->dockerfile(self::DOCKERFILE_CONTENT);

        exec(sprintf('%s %s 2>&1', escapeshellarg(self::SCRIPT), escapeshellarg($dockerfile)), $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertSame(gmdate('Ym'), substr($output[0], -6));
    }

    public function testAMissingDockerfileIsAnError(): void
    {
        exec(sprintf('%s %s 2>&1', escapeshellarg(self::SCRIPT), escapeshellarg($this->workDirectory . '/absent')), $output, $exitCode);

        self::assertNotSame(0, $exitCode);
    }

    private function dockerfile(string $content, string $name = 'Dockerfile.base'): string
    {
        $path = $this->workDirectory . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function tagOf(string $dockerfile, string $month): string
    {
        exec(sprintf('BASE_IMAGE_MONTH=%s %s %s 2>&1', escapeshellarg($month), escapeshellarg(self::SCRIPT), escapeshellarg($dockerfile)), $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        return $output[0];
    }
}
