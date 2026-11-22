<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Service\ESI\CharacterLocationReader;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1306')]
final class ProjectOverviewApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string OVERVIEW_URI = '/api/industry/projects/overview';
    private const string PLACE_ACTIONS_URI = '/api/industry/place-actions';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturn(null);
        self::getContainer()->set(CharacterLocationReader::class, $reader);
    }

    public function testOverviewIsRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', self::OVERVIEW_URI);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerReadsOneEntryPerProjectWithItsRoleOwnerAndAcceptedTeam(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', self::OVERVIEW_URI);

        self::assertResponseStatusCodeSame(200);
        $entries = $this->entriesByProject();
        $projectIds = array_keys($entries);
        sort($projectIds);
        $expectedIds = [$this->ishtarBatch->getId()->toRfc4122(), $this->hecateBatch->getId()->toRfc4122()];
        sort($expectedIds);
        self::assertSame($expectedIds, $projectIds);
        $ishtar = $entries[$this->ishtarBatch->getId()->toRfc4122()];
        self::assertSame(['awaited', 'flow', 'flowReason', 'flowUnknown', 'myRole', 'ownerName', 'projectId', 'team'], $this->sortedKeys($ishtar));
        self::assertSame('owner', $ishtar['myRole']);
        self::assertSame('Aria Solberg', $ishtar['ownerName']);
        self::assertSame([['name' => 'Brann Holt', 'role' => 'member']], $ishtar['team']);
        self::assertSame([], $entries[$this->hecateBatch->getId()->toRfc4122()]['team']);
    }

    public function testMemberSeesOnlyItsOwnProjectWithItsRoleAndTheNameOfTheOwnerMain(): void
    {
        $this->actAs($this->member);
        $this->request('GET', self::OVERVIEW_URI);

        self::assertResponseStatusCodeSame(200);
        $entries = $this->entriesByProject();
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122()], array_keys($entries));
        self::assertSame('member', $entries[$this->ishtarBatch->getId()->toRfc4122()]['myRole']);
        self::assertSame('Aria Solberg', $entries[$this->ishtarBatch->getId()->toRfc4122()]['ownerName']);
    }

    public function testPendingMemberAndOutsiderSeeNoProject(): void
    {
        foreach ([$this->pendingMember, $this->outsider] as $pilot) {
            $this->actAs($pilot);
            $this->request('GET', self::OVERVIEW_URI);

            self::assertResponseStatusCodeSame(200);
            self::assertSame([], $this->responseJson());
        }
    }

    public function testAwaitedCountsAreTheActionsEachParticipantIsGivenOnThatProject(): void
    {
        $ishtarId = $this->ishtarBatch->getId()->toRfc4122();
        $expected = [];
        foreach ([$this->owner, $this->member] as $pilot) {
            $this->actAs($pilot);
            $this->request('GET', self::PLACE_ACTIONS_URI);
            $expected[(string) $pilot->getMainCharacter()?->getName()] = count(array_filter($this->responseJson(), static fn (array $action): bool => $action['projectId'] === $ishtarId));
        }
        self::assertGreaterThan(0, $expected['Aria Solberg']);
        self::assertGreaterThan(0, $expected['Brann Holt']);

        $this->actAs($this->member);
        $this->request('GET', self::OVERVIEW_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['name' => 'Brann Holt', 'mine' => true, 'count' => $expected['Brann Holt']],
                ['name' => 'Aria Solberg', 'mine' => false, 'count' => $expected['Aria Solberg']],
            ],
            $this->entriesByProject()[$ishtarId]['awaited'],
        );
    }

    public function testFlowIsAnObjectOfCountsOrANullWithItsReason(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', self::OVERVIEW_URI);

        self::assertResponseStatusCodeSame(200);
        foreach ($this->entriesByProject() as $entry) {
            if (null === $entry['flow']) {
                self::assertIsString($entry['flowReason']);
                continue;
            }
            self::assertSame(['done', 'inProduction', 'onSite', 'out', 'toBring'], $this->sortedKeys($entry['flow']));
            self::assertNull($entry['flowReason']);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entriesByProject(): array
    {
        $entries = [];
        foreach ($this->responseJson() as $entry) {
            $entries[$entry['projectId']] = $entry;
        }

        return $entries;
    }

    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
