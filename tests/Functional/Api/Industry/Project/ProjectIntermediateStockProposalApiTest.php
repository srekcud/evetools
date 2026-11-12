<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\IndustryProjectBomLine;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-708')]
final class ProjectIntermediateStockProposalApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4_246;
    private const int HYDROGEN_FUEL_BLOCK_REQUIRED = 100;
    private const int HYDROGEN_FUEL_BLOCK_IN_HANGAR = 30;

    private IndustryProjectBomLine $hydrogenFuelBlockLine;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->hydrogenFuelBlockLine = $this->addJobLine($this->hecateBatch, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 'Hydrogen Fuel Block', 'component', 10, self::HYDROGEN_FUEL_BLOCK_REQUIRED, 10, 20, self::HECATE_TYPE_ID);
        $asset = (new CachedAsset())
            ->setItemId(1_070_800_000_001)
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK_TYPE_ID)
            ->setTypeName('Hydrogen Fuel Block')
            ->setQuantity(self::HYDROGEN_FUEL_BLOCK_IN_HANGAR)
            ->setLocationId(60_003_760)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setCharacter($this->owner->getMainCharacter())
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);
        $this->em->flush();
    }

    public function testOwnerOfASoloProjectReadsTheStockFoundInItsPersonalAssets(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->hecateBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(
            ['id' => $this->hecateBatch->getId()->toRfc4122(), 'state' => 'ready'],
            ['id' => $resource['id'], 'state' => $resource['state']],
        );
        self::assertCount(1, $resource['lines']);
        $line = $resource['lines'][0];
        self::assertArrayHasKey('containerQuantity', $line);
        self::assertNull($line['containerQuantity']);
        unset($line['containerQuantity']);
        self::assertSame(
            [
                'bomItemId' => $this->idOf($this->hydrogenFuelBlockLine),
                'demandQuantity' => self::HYDROGEN_FUEL_BLOCK_REQUIRED,
                'personalQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'proposedQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'remainingQuantity' => self::HYDROGEN_FUEL_BLOCK_REQUIRED,
                'stockQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'typeId' => self::HYDROGEN_FUEL_BLOCK_TYPE_ID,
                'typeName' => 'Hydrogen Fuel Block',
            ],
            $this->sortedKeys($line),
        );
    }

    public function testAcceptedMemberWhoDoesNotOwnTheProjectIsRefused(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->proposalUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupProjectIsNotSoloForItsOwner(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(['not_solo', []], [$resource['state'], $resource['lines']]);
    }

    private function proposalUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/intermediate-stock-proposal';
    }

    private function sortedKeys(array $payload): array
    {
        ksort($payload);

        return $payload;
    }
}
