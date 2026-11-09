<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\ProjectPaymentReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectPaymentReferenceTest extends TestCase
{
    public function testReferenceIsThePrefixAndTheFirstSixHexCharactersOfTheProjectIdInUpperCase(): void
    {
        self::assertSame('IP-4C81D2', ProjectPaymentReference::of(Uuid::fromString('4c81d2f0-aaaa-4bbb-8ccc-123456789abc')));
    }

    public function testTwoProjectsWithDifferentIdsGetDifferentReferences(): void
    {
        self::assertNotSame(
            ProjectPaymentReference::of(Uuid::fromString('4c81d2f0-aaaa-4bbb-8ccc-123456789abc')),
            ProjectPaymentReference::of(Uuid::fromString('9f00a1f0-aaaa-4bbb-8ccc-123456789abc')),
        );
    }
}
