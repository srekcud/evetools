<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-319')]
final class ProjectJobLocationsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ESI_JOB_ID = 880_319_001;
    private const int PERSONAL_BOX_ITEM_ID = 1_049_319_000_001;
    private const int UNRESOLVED_LOCATION_ID = 1_049_319_000_999;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();

        $installer = $this->owner->getMainCharacter();
        $this->em->persist(
            (new CachedAsset())
                ->setItemId(self::PERSONAL_BOX_ITEM_ID)
                ->setTypeId(17_366)
                ->setTypeName('Station Container')
                ->setQuantity(1)
                ->setLocationId(60_003_760)
                ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
                ->setLocationType('station')
                ->setItemName('Perso box')
                ->setCharacter($installer)
                ->setIsCorporationAsset(false),
        );
        $this->em->persist(
            (new CachedIndustryJob())
                ->setCharacter($installer)
                ->setJobId(self::ESI_JOB_ID)
                ->setActivityId(1)
                ->setBlueprintTypeId(12_006)
                ->setProductTypeId(self::ISHTAR_TYPE_ID)
                ->setRuns(self::ISHTAR_RUNS)
                ->setStatus('delivered')
                ->setStartDate(new \DateTimeImmutable('-2 days'))
                ->setEndDate(new \DateTimeImmutable('-1 day'))
                ->setBlueprintLocationId(self::PERSONAL_BOX_ITEM_ID)
                ->setOutputLocationId(self::UNRESOLVED_LOCATION_ID),
        );
        $this->ishtarJobLine->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId(self::ESI_JOB_ID)
                ->setRuns(self::ISHTAR_RUNS)
                ->setStatus('delivered'),
        );
        $this->em->flush();
    }

    public function testOwnerSeesWhereEachJobTookItsBlueprintAndDeliveredItsProducts(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseIsSuccessful();
        $locations = $this->jobMatchOfTheIshtarLine()['locations'];
        self::assertSame(
            [
                ['container', 'Perso box', 'unknown', 'no_director'],
                [null, null, 'unknown', 'location_unresolved'],
            ],
            [
                [$locations['blueprint']['kind'], $locations['blueprint']['name'], $locations['blueprint']['status'], $locations['blueprint']['unknownReason']],
                [$locations['output']['kind'], $locations['output']['name'], $locations['output']['status'], $locations['output']['unknownReason']],
            ],
        );
        self::assertSame([self::PERSONAL_BOX_ITEM_ID, self::UNRESOLVED_LOCATION_ID], [$locations['blueprint']['locationId'], $locations['output']['locationId']]);
    }

    public function testParticipantWhoIsNeitherOwnerNorAdminDoesNotSeeTheLocations(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertResponseIsSuccessful();
        self::assertNull($this->jobMatchOfTheIshtarLine()['locations']);
    }

    public function testLinkWhoseCachedJobIsGoneHasNoLocations(): void
    {
        $job = $this->em->getRepository(CachedIndustryJob::class)->findOneBy(['jobId' => self::ESI_JOB_ID]);
        $this->em->remove($job);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/bom');

        self::assertNull($this->jobMatchOfTheIshtarLine()['locations']);
    }

    private function jobMatchOfTheIshtarLine(): array
    {
        foreach ($this->responseJson() as $line) {
            if ($line['id'] === $this->idOf($this->ishtarJobLine)) {
                return $line['jobMatches'][0];
            }
        }

        self::fail('Ishtar job line missing from the BOM');
    }
}
