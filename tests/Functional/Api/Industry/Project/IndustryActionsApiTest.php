<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-335')]
final class IndustryActionsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string ACTIONS_URI = '/api/industry/actions';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testOwnerOfTwoProjectsReadsTheBlockedProjectBeforeTheContributionsToReview(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [
                    'projectId' => $this->hecateBatch->getId()->toRfc4122(),
                    'projectName' => 'Hecate batch',
                    'code' => 'source_material',
                    'blocked' => true,
                    'tab' => 'materials',
                    'targetStatus' => null,
                    'bomLineId' => $this->idOf($this->hecateTritaniumLine),
                    'typeId' => self::TRITANIUM_TYPE_ID,
                    'typeName' => 'Tritanium',
                    'quantity' => 40_000,
                ],
                [
                    'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
                    'projectName' => 'Ishtar batch',
                    'code' => 'review_contributions',
                    'blocked' => false,
                    'tab' => 'contributions',
                    'targetStatus' => null,
                    'bomLineId' => null,
                    'typeId' => null,
                    'typeName' => null,
                    'quantity' => 1,
                ],
            ],
            $this->responseJson(),
        );
    }

    public function testAcceptedMemberReadsOnlyTheProjectHeBelongsTo(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->ishtarBatch->getId()->toRfc4122(), 'source_material', true, self::TRITANIUM_TYPE_ID, 12_000]],
            array_map(
                static fn (array $entry): array => [$entry['projectId'] ?? null, $entry['code'] ?? null, $entry['blocked'] ?? null, $entry['typeId'] ?? null, $entry['quantity'] ?? null],
                $this->responseJson(),
            ),
        );
    }

    public function testPendingParticipantReadsAnEmptyQueue(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testOutsiderReadsAnEmptyQueue(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', self::ACTIONS_URI);

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testQueueIsRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', self::ACTIONS_URI);

        self::assertResponseStatusCodeSame(401);
    }
}
