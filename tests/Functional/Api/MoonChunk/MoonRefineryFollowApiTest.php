<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonChunk;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-274')]
final class MoonRefineryFollowApiTest extends WebTestCase
{
    use DrivesMoonChunkApi;

    private const string FOLLOW_ENDPOINT = '/api/mining/followed-refineries';
    private const string EXTRACTIONS_ENDPOINT = '/api/mining/corporation-extractions';
    private const int ILYRA_REFINERY_ID = 1_035_000_000_101;
    private const int CYRA_REFINERY_ID = 1_035_000_000_102;

    protected function setUp(): void
    {
        $this->seedMoonChunkWorld();
        $this->aria->setMainCharacter($this->aria->getCharacters()->first());
        $this->brann->setMainCharacter($this->brann->getCharacters()->first());
        $this->em->flush();
    }

    public function testAriaFollowsHerRefineryTwiceWithASingleFollowThenUnfollowsItAndOnlyShesAllowedToStopHerFollow(): void
    {
        $this->as($this->aria);

        $this->request('POST', self::FOLLOW_ENDPOINT, ['structureId' => self::ILYRA_REFINERY_ID]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        $this->request('POST', self::FOLLOW_ENDPOINT, ['structureId' => self::ILYRA_REFINERY_ID]);
        self::assertResponseStatusCodeSame(201);
        $countAfterTwoFollows = $this->followCount();
        $this->as($this->brann);
        $this->request('DELETE', self::FOLLOW_ENDPOINT . '/' . self::ILYRA_REFINERY_ID);
        $statusOfBrannDelete = $this->client->getResponse()->getStatusCode();
        $this->as($this->aria);
        $this->request('DELETE', self::FOLLOW_ENDPOINT . '/' . self::ILYRA_REFINERY_ID);
        $statusOfAriaDelete = $this->client->getResponse()->getStatusCode();
        $countAfterDelete = $this->followCount();
        $this->request('DELETE', self::FOLLOW_ENDPOINT . '/' . self::ILYRA_REFINERY_ID);

        self::assertSame(
            [self::ILYRA_REFINERY_ID, self::ILYRA_CORPORATION_ID, '2026-11-05T19:00:00+00:00'],
            [$created['structureId'], $created['corporationId'], $created['createdAt']],
        );
        self::assertSame([1, 404, 204, 0, 404], [$countAfterTwoFollows, $statusOfBrannDelete, $statusOfAriaDelete, $countAfterDelete, $this->client->getResponse()->getStatusCode()]);
    }

    public function testRefineryWithoutExtractionOfTheCorporationAndMissingStructureIdAreRefusedAndNothingIsStored(): void
    {
        $this->as($this->aria);

        $this->request('POST', self::FOLLOW_ENDPOINT, ['structureId' => self::CYRA_REFINERY_ID]);
        $statusOfForeignRefinery = $this->client->getResponse()->getStatusCode();
        $this->request('POST', self::FOLLOW_ENDPOINT, []);

        self::assertSame([400, 422, 0], [$statusOfForeignRefinery, $this->client->getResponse()->getStatusCode(), $this->followCount()]);
    }

    public function testCorporationExtractionsListTheRefineriesFollowedByTheUserOnly(): void
    {
        $this->as($this->brann);
        $this->request('POST', self::FOLLOW_ENDPOINT, ['structureId' => self::CYRA_REFINERY_ID]);
        $this->as($this->aria);
        $this->request('POST', self::FOLLOW_ENDPOINT, ['structureId' => self::ILYRA_REFINERY_ID]);

        $this->request('GET', self::EXTRACTIONS_ENDPOINT);

        self::assertResponseIsSuccessful();
        $corporations = $this->responseJson()['corporations'];
        self::assertSame([self::ILYRA_CORPORATION_ID], array_column($corporations, 'corporationId'));
        self::assertArrayHasKey('followedStructureIds', $corporations[0]);
        self::assertSame([self::ILYRA_REFINERY_ID], $corporations[0]['followedStructureIds']);
    }

    private function followCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM moon_refinery_follows');
    }
}
