<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Service\Industry\Project\LineMarkAccess;
use App\Tests\Unit\State\Processor\Industry\Project\MarksJobLinesOnIshtarBom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineMarkAccess::class)]
#[Group('issue-1209')]
final class LineMarkAccessTest extends TestCase
{
    use MarksJobLinesOnIshtarBom;

    protected function setUp(): void
    {
        $this->setUpMarkableIshtarProject();
    }

    #[DataProvider('roles')]
    public function testWhoMayMarkTheRamLine(string $userProperty, string $expectedOutcome): void
    {
        self::assertSame($expectedOutcome, $this->mayMarkOutcome($this->{$userProperty}, $this->ramLine));
    }

    public static function roles(): iterable
    {
        yield 'owner' => ['owner', self::GRANTED];
        yield 'admin' => ['admin', self::GRANTED];
        yield 'member who reserved the line' => ['reservingMember', self::GRANTED];
        yield 'member who reserved another line' => ['bystanderMember', self::NOT_MARKER];
        yield 'pending participant' => ['pendingUser', self::NOT_MARKER];
        yield 'user outside the project' => ['stranger', self::NOT_MARKER];
    }

    public function testMemberWhoReservedTheRamLineMayNotMarkTheIshtarLine(): void
    {
        self::assertSame(self::NOT_MARKER, $this->mayMarkOutcome($this->reservingMember, $this->ishtarLine));
    }

    private function mayMarkOutcome(User $user, IndustryProjectBomLine $line): string
    {
        return $this->outcomeOf(fn () => $this->lineMarkAccess()->assertMayMark($user, $this->ishtarProject, $line));
    }
}
