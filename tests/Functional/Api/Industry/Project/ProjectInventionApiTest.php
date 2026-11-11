<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-609')]
final class ProjectInventionApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string CHARACTER_NOT_FOUND = 'Character not found';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testOwnerOfAProjectWithoutAnInventedProductReadsAnEmptyComparison(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->inventionUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        $comparison = $this->responseJson();
        self::assertSame(
            [['id', 'products'], (string) $this->hecateBatch->getId()?->toRfc4122(), []],
            [array_keys($this->sortedByKey($comparison)), $comparison['id'], $comparison['products']],
        );
    }

    public function testAcceptedMemberReadsTheComparisonOfTheProject(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->inventionUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson()['products']);
    }

    public function testPendingParticipantIsRefused(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', $this->inventionUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testPilotOfAnotherCorporationIsRefused(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->inventionUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testComparisonOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', '/api/industry/projects/'.Uuid::v7()->toRfc4122().'/invention');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testInventorThatIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->inventionUri($this->hecateBatch).'?inventorCharacterId='.self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, self::CHARACTER_NOT_FOUND], $this->errorDetail());
    }

    public function testInventorOfAnotherUserIsNotFoundEvenWhenItIsTheCharacterOfTheOwner(): void
    {
        $ownerCharacterId = (string) $this->owner->getMainCharacter()?->getId()?->toRfc4122();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->inventionUri($this->ishtarBatch).'?inventorCharacterId='.$ownerCharacterId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, self::CHARACTER_NOT_FOUND], $this->errorDetail());
    }

    public function testInventorOfTheConnectedUserIsAccepted(): void
    {
        $memberCharacterId = (string) $this->member->getMainCharacter()?->getId()?->toRfc4122();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->inventionUri($this->ishtarBatch).'?inventorCharacterId='.$memberCharacterId);

        self::assertResponseStatusCodeSame(200);
    }

    private function inventionUri(IndustryProject $project): string
    {
        return '/api/industry/projects/'.$project->getId()?->toRfc4122().'/invention';
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function sortedByKey(array $values): array
    {
        ksort($values);

        return $values;
    }
}
