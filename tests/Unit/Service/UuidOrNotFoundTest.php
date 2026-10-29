<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UuidOrNotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(UuidOrNotFound::class)]
#[Group('issue-200')]
final class UuidOrNotFoundTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-200000000001';

    public function testValidUuidIsReturnedUnchanged(): void
    {
        $projectId = UuidOrNotFound::of(self::PROJECT_ID, 'Project not found');

        self::assertSame(self::PROJECT_ID, $projectId->toRfc4122());
    }

    #[DataProvider('identifiersThatAreNotUuids')]
    public function testIdentifierThatIsNotAUuidIsNotFoundWithTheGivenMessage(string $identifier, string $notFoundMessage): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage($notFoundMessage);

        UuidOrNotFound::of($identifier, $notFoundMessage);
    }

    public static function identifiersThatAreNotUuids(): iterable
    {
        yield 'letters' => ['abc', 'Project not found'];
        yield 'empty' => ['', 'Step not found'];
        yield 'number' => ['42', 'Structure not found'];
        yield 'truncated uuid' => ['0192f0c4-7b1e-7a3c-9d2e-2000000000', 'Purchase not found'];
    }
}
