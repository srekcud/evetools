<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1294')]
final class ProjectStationQuantitiesApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e52';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberReadsOneEntryPerBomTypeWithNullsKeptInTheResponse(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->stationQuantitiesUri());

        self::assertResponseStatusCodeSame(200);
        $entries = $this->responseJson();
        self::assertSame(
            [
                [self::ISHTAR_TYPE_ID, 'Ishtar', [$this->idOf($this->ishtarJobLine)]],
                [self::MORPHITE_TYPE_ID, 'Morphite', [$this->idOf($this->morphiteLine)]],
                [self::NOCXIUM_TYPE_ID, 'Nocxium', [$this->idOf($this->nocxiumLine)]],
                [self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', [$this->idOf($this->ramJobLine)]],
                [self::TRITANIUM_TYPE_ID, 'Tritanium', [$this->idOf($this->tritaniumLine)]],
            ],
            array_map(static fn (array $entry): array => [$entry['typeId'], $entry['typeName'], $entry['lineIds']], $entries),
        );
        foreach ($entries as $entry) {
            self::assertSame(['lineIds', 'stations', 'typeId', 'typeName', 'unknownReason'], $this->sortedKeys($entry));
            self::assertArrayHasKey('stations', $entry);
            self::assertArrayHasKey('unknownReason', $entry);
            if (null === $entry['stations']) {
                self::assertSame('plan_unavailable', $entry['unknownReason']);
            } else {
                self::assertIsArray($entry['stations']);
                self::assertNull($entry['unknownReason']);
            }
        }
    }

    public function testPilotOutsideTheProjectIsRefusedTheStationQuantities(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->stationQuantitiesUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/station-quantities');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testStationQuantitiesAreRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', $this->stationQuantitiesUri());

        self::assertResponseStatusCodeSame(401);
    }

    private function stationQuantitiesUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/station-quantities';
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }
}
