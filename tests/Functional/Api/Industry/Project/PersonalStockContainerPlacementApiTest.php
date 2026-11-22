<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryStructureConfig;
use App\Entity\PersonalStockContainer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1324')]
final class PersonalStockContainerPlacementApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ALPHA_LOCATION_ID = 60_003_760;
    private const int ALPHA_SOLAR_SYSTEM_ID = 30_000_142;
    private const int DECLARED_ITEM_ID = 1_132_402_000_001;

    private IndustryStructureConfig $alpha;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->alpha = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Alpha')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId(self::ALPHA_LOCATION_ID)
            ->setSolarSystemId(self::ALPHA_SOLAR_SYSTEM_ID)
            ->setRigs([]);
        $this->em->persist($this->alpha);
        $this->ishtarBatch->setWorkStations([
            'final_products' => ['structureId' => $this->alpha->getId()->toRfc4122(), 'recommendedStructureId' => null],
        ]);
        $this->em->flush();
    }

    public function testPlacingAProjectContainerNamedLikeADeclaredPersonalStockContainerIsRefusedWith409(): void
    {
        $this->em->persist(new PersonalStockContainer($this->owner, self::DECLARED_ITEM_ID, 'Mats', 'Reactions', self::ALPHA_LOCATION_ID, 'Alpha'));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/containers', ['locationId' => self::ALPHA_LOCATION_ID, 'name' => 'mats']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testPlacingAProjectContainerWithAnotherNameThanTheDeclaredOnesAnswersSuccess(): void
    {
        $this->em->persist(new PersonalStockContainer($this->owner, self::DECLARED_ITEM_ID, 'Mats', 'Reactions', self::ALPHA_LOCATION_ID, 'Alpha'));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/containers', ['locationId' => self::ALPHA_LOCATION_ID, 'name' => 'Ishtar mats']);

        self::assertResponseIsSuccessful();
        self::assertSame([[self::CONTAINER_NAME, null], ['Ishtar mats', self::ALPHA_LOCATION_ID]], $this->storedContainers());
    }

    private function storedContainers(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT name, location_id FROM industry_project_containers WHERE project_id = :projectId ORDER BY name COLLATE "C"',
            ['projectId' => $this->ishtarBatch->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [$row['name'], null === $row['location_id'] ? null : (int) $row['location_id']], $rows);
    }
}
