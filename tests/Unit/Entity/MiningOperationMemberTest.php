<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Character;
use App\Entity\MiningOperationMember;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationMember::class)]
#[Group('issue-262')]
final class MiningOperationMemberTest extends TestCase
{
    private const string EARLIER_JOINED_AT = '2026-10-01T18:00:00+00:00';

    public function testRemovedAcceptedAdminBecomesARemovedPlainMemberWithoutEnrolledCharacters(): void
    {
        $member = (new MiningOperationMember())
            ->setRole(MiningOperationMemberRole::Admin)
            ->setStatus(MiningOperationMemberStatus::Accepted)
            ->enrol($this->character('Brann Holt', 2_112_262_201))
            ->enrol($this->character('Brann II', 2_112_262_202));

        $member->remove();

        self::assertSame(MiningOperationMemberStatus::Removed, $member->getStatus());
        self::assertSame(MiningOperationMemberRole::Member, $member->getRole());
        self::assertCount(0, $member->getCharacters());
    }

    public function testRemovedMemberRequestingToRejoinIsPendingPlainMemberEnrolledWithTheTickedCharacterSinceNow(): void
    {
        $member = (new MiningOperationMember())
            ->setRole(MiningOperationMemberRole::Admin)
            ->setStatus(MiningOperationMemberStatus::Removed);
        (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable(self::EARLIER_JOINED_AT));
        $brannHolt = $this->character('Brann Holt', 2_112_262_201);
        $requestedAt = new \DateTimeImmutable();

        $member->requestRejoin([$brannHolt]);

        self::assertSame(MiningOperationMemberStatus::Pending, $member->getStatus());
        self::assertSame(MiningOperationMemberRole::Member, $member->getRole());
        self::assertSame(['Brann Holt'], array_map(
            static fn ($enrolment): string => $enrolment->getCharacter()->getName(),
            array_values($member->getCharacters()->toArray()),
        ));
        self::assertGreaterThanOrEqual($requestedAt, $member->getJoinedAt());
    }

    private function character(string $name, int $eveCharacterId): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_262_101)
            ->setCorporationName('Corporation 98262101');
    }
}
