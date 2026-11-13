<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[Group('issue-807')]
final class ProjectBomLineMarkApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testOwnerMarksTheRamLineDoneForAllItsRunsAndTheChainShowsItDoneWithItsMarkWhileTheIshtarIsNoLongerBlocked(): void
    {
        $this->requestAs($this->owner, 'GET', $this->chainUri());
        self::assertSame(['unknown', 'blocked'], $this->statusesOfRamAndIshtar());

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => self::RAM_RUNS]);

        self::assertResponseStatusCodeSame(200);
        $mark = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->ramJobLine), $this->idOf($this->ramJobLine), 'done', self::RAM_RUNS],
            [$mark['id'], $mark['lineId'], $mark['state'], $mark['runs']],
        );
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $mark['markedAt']));
        $this->requestAs($this->owner, 'GET', $this->chainUri());
        self::assertSame(['done', 'unknown'], $this->statusesOfRamAndIshtar());
        self::assertSame(['state' => 'done', 'runs' => self::RAM_RUNS], $this->chainNode($this->ramJobLine)['mark']);
    }

    public function testRemovingTheMarkGivesBackTheStatusesOfTheChainWithoutMark(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => self::RAM_RUNS]);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->owner, 'DELETE', $this->markUri($this->ramJobLine));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedMarksOf($this->ramJobLine));
        $this->requestAs($this->owner, 'GET', $this->chainUri());
        self::assertSame(['unknown', 'blocked'], $this->statusesOfRamAndIshtar());
        $ramNode = $this->chainNode($this->ramJobLine);
        self::assertArrayHasKey('mark', $ramNode);
        self::assertNull($ramNode['mark']);
    }

    public function testMarkingTheLineAgainReplacesItsMarkAndLeavesOneStoredRow(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => 2]);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'launched', 'runs' => 3]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['state' => 'launched', 'runs' => 3]], $this->storedMarksOf($this->ramJobLine));
        $this->requestAs($this->owner, 'GET', $this->chainUri());
        self::assertSame(['state' => 'launched', 'runs' => 3], $this->chainNode($this->ramJobLine)['mark']);
        self::assertSame('active', $this->chainNode($this->ramJobLine)['status']);
    }

    public function testAcceptedMemberReadsTheMarkOfTheOwnerOnTheChain(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'launched', 'runs' => 2]);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->member, 'GET', $this->chainUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['active', ['state' => 'launched', 'runs' => 2]], [$this->chainNode($this->ramJobLine)['status'], $this->chainNode($this->ramJobLine)['mark']]);
    }

    public function testAcceptedMemberWhoIsNotTheOwnerCannotMarkNorRemoveAMark(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'launched', 'runs' => 2]);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->member, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => 3]);
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->member, 'DELETE', $this->markUri($this->ramJobLine));
        self::assertResponseStatusCodeSame(403);

        self::assertSame([['state' => 'launched', 'runs' => 2]], $this->storedMarksOf($this->ramJobLine));
    }

    public function testPilotOfAnotherCorporationCannotMarkALine(): void
    {
        $this->requestAs($this->outsider, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => 3]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->storedMarksOf($this->ramJobLine));
    }

    public function testRunsOutsideOneToTheRunsOfTheLineAreRefusedWithABadRequestAndNoMark(): void
    {

        foreach ([0, -1, self::RAM_RUNS + 1, 3_000_000_000] as $runs) {
            $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => $runs]);
            self::assertResponseStatusCodeSame(400, 'runs '.$runs);
        }

        self::assertSame([], $this->storedMarksOf($this->ramJobLine));
    }

    public function testRunsOutOfBoundsLeaveTheExistingMarkAsItWas(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'launched', 'runs' => 2]);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => self::RAM_RUNS + 1]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([['state' => 'launched', 'runs' => 2]], $this->storedMarksOf($this->ramJobLine));
    }

    public function testUnknownStateIsRefusedWithABadRequest(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'paused', 'runs' => 2]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->storedMarksOf($this->ramJobLine));
    }

    public function testMaterialLineCannotBeMarked(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->tritaniumLine), ['state' => 'done', 'runs' => 1]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->storedMarksOf($this->tritaniumLine));
    }

    public function testBodyWithoutStateOrRunsIsRefusedAsUnprocessableAndNeverAsAServerError(): void
    {

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['runs', 'state'], $this->sortedViolatedPaths());

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['runs' => 2]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['state'], $this->sortedViolatedPaths());

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['runs'], $this->sortedViolatedPaths());

        self::assertSame([], $this->storedMarksOf($this->ramJobLine));
    }

    public function testCompletedProjectRefusesTheMarkAndItsRemoval(): void
    {
        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'launched', 'runs' => 2]);
        self::assertResponseStatusCodeSame(200);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => 3]);
        self::assertResponseStatusCodeSame(400);
        $this->requestAs($this->owner, 'DELETE', $this->markUri($this->ramJobLine));
        self::assertResponseStatusCodeSame(400);

        self::assertSame([['state' => 'launched', 'runs' => 2]], $this->storedMarksOf($this->ramJobLine));
    }

    public function testLineOfAnotherProjectAndUnknownLineAreNotFound(): void
    {

        $this->requestAs($this->owner, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($this->hecateTritaniumLine).'/mark', ['state' => 'done', 'runs' => 1]);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->owner, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.Uuid::v7()->toRfc4122().'/mark', ['state' => 'done', 'runs' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovingTheMarkOfALineWithoutMarkIsNotFound(): void
    {
        $this->requestAs($this->owner, 'DELETE', $this->markUri($this->ramJobLine));

        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryAcceptedParticipantIsToldAfterTheMarkAndAfterItsRemovalAndAPendingOneIsNot(): void
    {
        $expected = $this->expectedIndustryProjectEvents();

        $this->requestAs($this->owner, 'POST', $this->markUri($this->ramJobLine), ['state' => 'done', 'runs' => 3]);
        self::assertSame($expected, $this->publishedIndustryProjectEvents());

        $this->publishedUpdates = [];
        $this->requestAs($this->owner, 'DELETE', $this->markUri($this->ramJobLine));
        self::assertSame($expected, $this->publishedIndustryProjectEvents());
    }

    private function requestAs(User $user, string $method, string $uri, ?array $body = null): void
    {
        $this->actAs($user);
        $this->request($method, $uri, $body);
    }

    private function markUri(IndustryProjectBomLine $line): string
    {
        return $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($line).'/mark';
    }

    private function chainUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/chain';
    }

    private function chainNode(IndustryProjectBomLine $line): array
    {
        return array_column($this->responseJson()['nodes'], null, 'id')[$this->idOf($line)];
    }

    private function statusesOfRamAndIshtar(): array
    {
        return [$this->chainNode($this->ramJobLine)['status'], $this->chainNode($this->ishtarJobLine)['status']];
    }

    private function storedMarksOf(IndustryProjectBomLine $line): array
    {
        return array_map(
            static fn (array $row): array => ['state' => $row['state'], 'runs' => $row['runs']],
            $this->em->getConnection()->fetchAllAssociative('SELECT state, runs FROM industry_project_bom_line_marks WHERE line_id = ?', [$this->idOf($line)]),
        );
    }

    private function sortedViolatedPaths(): array
    {
        $paths = $this->violatedPaths();
        sort($paths);

        return $paths;
    }

    private function publishedIndustryProjectEvents(): array
    {
        $events = array_map(
            static fn (Update $update): array => [$update->getTopics()[0], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['data']],
            $this->publishedUpdates,
        );
        sort($events);

        return $events;
    }

    private function expectedIndustryProjectEvents(): array
    {
        $data = ['projectId' => $this->ishtarBatch->getId()->toRfc4122(), 'lineId' => $this->idOf($this->ramJobLine)];
        $events = array_map(
            fn (User $user): array => ['/user/'.$user->getId()->toRfc4122().'/sync/industry-project', $data],
            [$this->owner, $this->member],
        );
        sort($events);

        return $events;
    }
}
