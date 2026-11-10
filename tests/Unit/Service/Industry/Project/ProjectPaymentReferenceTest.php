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

    public function testAnyProjectReferenceIsRecognisedInAFreeTextWhateverItsCase(): void
    {
        self::assertTrue(ProjectPaymentReference::appearsIn('IP-4C81D2'));
        self::assertTrue(ProjectPaymentReference::appearsIn('payout ip-9f00a1 thanks'));
        self::assertFalse(ProjectPaymentReference::appearsIn('for the beers'));
        self::assertFalse(ProjectPaymentReference::appearsIn('IP-4C81'));
        self::assertFalse(ProjectPaymentReference::appearsIn('SHIP-4C81D2X'));
    }

    public function testReferenceOfAProjectIsFoundInATextWhateverItsCase(): void
    {
        $projectId = Uuid::fromString('4c81d2f0-aaaa-4bbb-8ccc-123456789abc');

        self::assertTrue(ProjectPaymentReference::isMentionedIn($projectId, 'payout ip-4c81d2 thanks'));
        self::assertFalse(ProjectPaymentReference::isMentionedIn($projectId, 'payout IP-9F00A1'));
        self::assertFalse(ProjectPaymentReference::isMentionedIn($projectId, null));
    }

    public function testTwoProjectsWithDifferentIdsGetDifferentReferences(): void
    {
        self::assertNotSame(
            ProjectPaymentReference::of(Uuid::fromString('4c81d2f0-aaaa-4bbb-8ccc-123456789abc')),
            ProjectPaymentReference::of(Uuid::fromString('9f00a1f0-aaaa-4bbb-8ccc-123456789abc')),
        );
    }
}
