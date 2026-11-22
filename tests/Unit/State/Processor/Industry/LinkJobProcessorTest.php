<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Input\Industry\LinkJobInput;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\ManualJobLinker;
use App\State\Processor\Industry\LinkJobProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkJobProcessor::class)]
#[CoversClass(ManualJobLinker::class)]
#[CoversClass(IndustryJobMatcher::class)]
#[Group('issue-362')]
#[Group('issue-363')]
#[AllowMockObjectsWithoutExpectations]
final class LinkJobProcessorTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;
    use BuildsManualJobLinker;

    private const int OWNER_ISHTAR_JOB_ID = 7_362_101;
    private const int OWNER_RAM_JOB_ID = 7_362_102;
    private const int ADMIN_ISHTAR_JOB_ID = 7_362_103;
    private const int ISHTAR_JOB_LINKED_ELSEWHERE_ID = 7_362_104;
    private const int OWNER_ISHTAR_INVENTION_JOB_ID = 7_362_105;
    private const int STRANGER_ISHTAR_JOB_ID = 7_362_106;
    private const int PENDING_ISHTAR_JOB_ID = 7_362_107;

    private array $jobs = [];

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->givenJob(self::OWNER_ISHTAR_JOB_ID, $this->ownerAltCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 3);
        $this->givenJob(self::OWNER_RAM_JOB_ID, $this->ownerMainCharacter, self::RAM_BLUEPRINT_TYPE_ID, 4);
        $this->givenJob(self::ADMIN_ISHTAR_JOB_ID, $this->adminCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 2);
        $this->givenJob(self::ISHTAR_JOB_LINKED_ELSEWHERE_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 1);
        $this->givenJob(self::OWNER_ISHTAR_INVENTION_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, 1, IndustryActivityType::Invention->value);
        $linkedElsewhere = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_LINKED_ELSEWHERE_ID)->setRuns(1);
        $this->ramLine->addJobMatch($linkedElsewhere);
        $this->storedJobMatches[] = $linkedElsewhere;
    }

    public function testOwnerLinksTheJobOfHisAltToTheIshtarLineAsAManualLink(): void
    {
        $resource = $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::OWNER_ISHTAR_JOB_ID);

        self::assertInstanceOf(ProjectJobMatchResource::class, $resource);
        self::assertSame([self::OWNER_ISHTAR_JOB_ID, 3], [$resource->esiJobId, $resource->runs]);
        $match = $this->ishtarLine->getJobMatches()->first();
        self::assertSame(
            [self::OWNER_ISHTAR_JOB_ID, 3, 'Aria Alt', true, $this->ishtarLine],
            [$match->getEsiJobId(), $match->getRuns(), $match->getCharacterName(), $match->isManual(), $match->getBomLine()],
        );
        self::assertSame([3, 0], [$this->ishtarLine->getMatchedRuns(), $this->ishtarLine->getFulfilledQuantity()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 3]], $this->refreshedLines);
    }

    #[Group('issue-1250')]
    public function testOwnerLinksOnlyTheRequestedRunsOfTheJobAndTheLineKeepsOnlyThatShare(): void
    {
        $resource = $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::ADMIN_ISHTAR_JOB_ID, 1);

        self::assertSame([self::ADMIN_ISHTAR_JOB_ID, 1], [$resource->esiJobId, $resource->runs]);
        $match = $this->ishtarLine->getJobMatches()->first();
        self::assertSame([1, 625_000.25], [$match->getRuns(), $match->getCost()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 1]], $this->refreshedLines);
    }

    public function testJobOfAnotherBlueprintIsRefusedWithoutLink(): void
    {
        self::assertSame(
            '400 Job blueprint does not match BOM line blueprint',
            $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::OWNER_RAM_JOB_ID)),
        );
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    public function testJobOfAUserOutsideTheProjectOrOfAPendingRequestIsAnsweredLikeAnUnknownJob(): void
    {
        $stranger = new User();
        $this->givenJob(self::STRANGER_ISHTAR_JOB_ID, $this->characterOf($stranger, 'Dax Orlan'), self::ISHTAR_BLUEPRINT_TYPE_ID, 2);
        $pending = new User();
        $this->givenJob(self::PENDING_ISHTAR_JOB_ID, $this->characterOf($pending, 'Cyra Lune'), self::ISHTAR_BLUEPRINT_TYPE_ID, 2);
        $request = (new IndustryProjectParticipant())->setUser($pending)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Pending);
        $this->ishtarProject->addMember($request);
        $this->participants[] = $request;

        self::assertSame(
            ['400 ESI job 7362106 not found', '400 ESI job 7362107 not found', '400 ESI job 7362999 not found'],
            [
                $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::STRANGER_ISHTAR_JOB_ID)),
                $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::PENDING_ISHTAR_JOB_ID)),
                $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, 7_362_999)),
            ],
        );
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    public function testOwnerLinksTheJobOfAnAcceptedParticipant(): void
    {
        $resource = $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::ADMIN_ISHTAR_JOB_ID);

        self::assertSame([self::ADMIN_ISHTAR_JOB_ID, 2], [$resource->esiJobId, $resource->runs]);
        $match = $this->ishtarLine->getJobMatches()->first();
        self::assertSame(['Brann Holt', true], [$match->getCharacterName(), $match->isManual()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 2]], $this->refreshedLines);
    }

    public function testAcceptedAdminLinksHisOwnJobAsAManualLink(): void
    {
        $resource = $this->link($this->admin, $this->ishtarProject, $this->ishtarLine, self::ADMIN_ISHTAR_JOB_ID);

        self::assertSame([self::ADMIN_ISHTAR_JOB_ID, 2], [$resource->esiJobId, $resource->runs]);
        $match = $this->ishtarLine->getJobMatches()->first();
        self::assertSame(['Brann Holt', true, $this->ishtarLine], [$match->getCharacterName(), $match->isManual(), $match->getBomLine()]);
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 2]], $this->refreshedLines);
    }

    public function testJobAlreadyLinkedIsRefusedWithoutLink(): void
    {
        self::assertSame(
            '400 ESI job 7362104 is already linked to a BOM line',
            $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::ISHTAR_JOB_LINKED_ELSEWHERE_ID)),
        );
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    public function testJobOfAnotherActivityThanTheLineIsRefused(): void
    {
        self::assertSame(
            '400 Job activity does not match BOM line activity',
            $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->ishtarLine, self::OWNER_ISHTAR_INVENTION_JOB_ID)),
        );
    }

    public function testParticipantCannotLinkTheJobOfAnotherParticipant(): void
    {
        self::assertSame(
            '400 ESI job 7362101 not found',
            $this->outcomeOf(fn () => $this->link($this->admin, $this->ishtarProject, $this->ishtarLine, self::OWNER_ISHTAR_JOB_ID)),
        );
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    public function testUserOutsideTheProjectIsDenied(): void
    {
        $stranger = new User();
        $this->givenJob(self::STRANGER_ISHTAR_JOB_ID, $this->characterOf($stranger, 'Dax Orlan'), self::ISHTAR_BLUEPRINT_TYPE_ID, 2);

        self::assertSame(
            '403 You do not have access to this project',
            $this->outcomeOf(fn () => $this->link($stranger, $this->ishtarProject, $this->ishtarLine, self::STRANGER_ISHTAR_JOB_ID)),
        );
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertSame([], $this->refreshedLines);
    }

    public function testLineOfAnotherProjectIsNotFound(): void
    {
        self::assertSame(
            '404 BOM line not found',
            $this->outcomeOf(fn () => $this->link($this->owner, $this->ishtarProject, $this->hecateTritaniumLine, self::OWNER_ISHTAR_JOB_ID)),
        );
    }

    private function link(User $user, IndustryProject $project, IndustryProjectBomLine $line, int $esiJobId, ?int $runs = null): mixed
    {
        $input = new LinkJobInput();
        $input->esiJobId = $esiJobId;
        $input->runs = $runs;

        return $this->processor($user)->process($input, new Post(), $this->lineUriVariables($project, $line));
    }

    private function processor(User $user): LinkJobProcessor
    {
        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findByJobId')->willReturnCallback(fn (int $jobId): ?CachedIndustryJob => $this->jobs[$jobId] ?? null);

        return new LinkJobProcessor(
            $this->securityOf($user),
            $this->projectRepository(),
            $this->bomLineRepository(),
            $jobRepository,
            $this->accessChecker(),
            $this->manualJobLinker(),
        );
    }

    private function givenJob(int $jobId, Character $installer, int $blueprintTypeId, int $runs, int $activityId = 1): void
    {
        $this->jobs[$jobId] = (new CachedIndustryJob())
            ->setJobId($jobId)
            ->setCharacter($installer)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setRuns($runs)
            ->setStatus('active')
            ->setCost(1_250_000.5)
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'));
    }
}
