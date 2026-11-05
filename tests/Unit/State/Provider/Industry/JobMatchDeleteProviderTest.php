<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Repository\IndustryProjectJobMatchRepository;
use App\State\Provider\Industry\JobMatchDeleteProvider;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JobMatchDeleteProvider::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class JobMatchDeleteProviderTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private IndustryProjectJobMatch $ishtarJobMatch;
    private IndustryProjectJobMatch $hecateJobMatch;

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->ishtarJobMatch = $this->jobMatchOn($this->ishtarLine, 7_362_401);
        $hecateJobLine = $this->jobLine($this->hecateProject, 35683, 'Hecate', 'final', 1, 1);
        $this->hecateJobMatch = $this->jobMatchOn($hecateJobLine, 7_362_402);
    }

    public function testOwnerGetsTheJobMatchOfHisProjectAsItsResource(): void
    {
        $resource = $this->provide($this->owner, $this->ishtarProject, $this->ishtarJobMatch->getId()->toRfc4122());

        self::assertInstanceOf(ProjectJobMatchResource::class, $resource);
        self::assertSame($this->ishtarJobMatch->getId()->toRfc4122(), $resource->id);
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDenied(): void
    {
        self::assertSame(
            self::OWNER_ONLY,
            $this->outcomeOf(fn () => $this->provide($this->admin, $this->ishtarProject, $this->ishtarJobMatch->getId()->toRfc4122())),
        );
    }

    public function testJobMatchOfAnotherProjectIsNotFound(): void
    {
        self::assertSame(
            '404 Job match not found',
            $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, $this->hecateJobMatch->getId()->toRfc4122())),
        );
    }

    public function testJobMatchIdThatIsNotAUuidIsNotFound(): void
    {
        self::assertSame(
            '404 Job match not found',
            $this->outcomeOf(fn () => $this->provide($this->owner, $this->ishtarProject, 'm-1')),
        );
    }

    private function provide(User $user, IndustryProject $project, string $jobMatchId): mixed
    {
        $matchRepository = $this->createStub(IndustryProjectJobMatchRepository::class);
        $matchRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProjectJobMatch => array_find(
                [$this->ishtarJobMatch, $this->hecateJobMatch],
                static fn (IndustryProjectJobMatch $match): bool => $match->getId()->equals($id),
            ),
        );

        return (new JobMatchDeleteProvider($this->securityOf($user), $this->projectRepository(), $matchRepository, $this->accessChecker()))
            ->provide(new Delete(), ['projectId' => $project->getId()->toRfc4122(), 'id' => $jobMatchId]);
    }

    private function jobMatchOn(IndustryProjectBomLine $line, int $esiJobId): IndustryProjectJobMatch
    {
        $match = (new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setRuns(1)->setStatus('active');
        (new \ReflectionProperty(IndustryProjectJobMatch::class, 'id'))->setValue($match, Uuid::v4());
        $line->addJobMatch($match);

        return $match;
    }
}
