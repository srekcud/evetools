<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\BomLineMarkState;
use App\State\Provider\Industry\Project\BomLineMarkProvider;
use App\Tests\Unit\State\Processor\Industry\Project\MarksJobLinesOnIshtarBom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BomLineMarkProvider::class)]
#[Group('issue-807')]
final class BomLineMarkProviderTest extends TestCase
{
    use MarksJobLinesOnIshtarBom;

    protected function setUp(): void
    {
        $this->setUpMarkableIshtarProject();
    }

    public function testOwnerGetsTheStepMarkOfTheRamLineAsItsResource(): void
    {
        $this->markOn($this->ramLine, BomLineMarkState::Launched, 3);

        $resource = $this->provide($this->owner, $this->ishtarProject, $this->ramLine);

        self::assertSame(
            ['id' => $this->ramLine->getId()->toRfc4122(), 'lineId' => $this->ramLine->getId()->toRfc4122(), 'state' => 'launched', 'runs' => 3, 'markedAt' => self::INITIAL_MARK_DATE],
            get_object_vars($resource),
        );
    }

    public function testLineWithoutStepMarkIsNotFound(): void
    {
        self::assertSame('404 No mark on this line', $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, $this->ramLine)));
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDenied(): void
    {
        $this->markOn($this->ramLine, BomLineMarkState::Launched, 3);

        self::assertSame(self::OWNER_ONLY, $this->outcomeOf(fn () => $this->provide($this->admin, $this->ishtarProject, $this->ramLine)));
    }

    public function testLineOfAnotherProjectIsNotFoundEvenWhenItCarriesAStepMark(): void
    {
        self::assertSame(
            '404 BOM line not found',
            $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, $this->hecateTritaniumLine)),
        );
    }

    private function provide(User $user, IndustryProject $project, IndustryProjectBomLine $line): object
    {
        $provider = new BomLineMarkProvider($this->securityOf($user), $this->projectRepository(), $this->bomLineRepository(), $this->accessChecker());

        return $provider->provide(new Delete(), $this->lineUriVariables($project, $line));
    }
}
