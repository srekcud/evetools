<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-337')]
final class ProjectChainApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testOwnerReadsTheChainOfHisProjectFromItsMaterialsToItsSale(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->chainUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [$this->idOf($this->morphiteLine), 'material', 'Morphite', 'active', 'known', 55_500.0, null],
                [$this->idOf($this->nocxiumLine), 'material', 'Nocxium', 'todo', 'none', null, null],
                [$this->idOf($this->tritaniumLine), 'material', 'Tritanium', 'todo', 'none', null, null],
                [$this->idOf($this->ramJobLine), 'component', 'R.A.M.- Starship Tech', 'todo', 'none', null, $this->idOf($this->ishtarJobLine)],
                [$this->idOf($this->ishtarJobLine), 'product', 'Ishtar', 'blocked', 'known', 499_500.0, null],
                ['sale', 'sale', null, 'active', 'none', null, null],
            ],
            $this->nodeRows(),
        );
    }

    public function testOwnerReadsWhoSuppliedTheProductAndWhatTheSalesBrought(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->chainUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $nodes = $this->nodesById();
        self::assertSame(
            [
                [['participantId' => $this->idOf($this->ownerMembership), 'characterName' => 'Aria Solberg', 'type' => 'job_install', 'quantity' => self::ISHTAR_RUNS]],
                [self::ISHTAR_RUNS, 4, 8, 'manufacturing', []],
                [2, 3_000_000.0],
            ],
            [
                $nodes[$this->idOf($this->ishtarJobLine)]['suppliers'],
                [
                    $nodes[$this->idOf($this->ishtarJobLine)]['runs'],
                    $nodes[$this->idOf($this->ishtarJobLine)]['meLevel'],
                    $nodes[$this->idOf($this->ishtarJobLine)]['teLevel'],
                    $nodes[$this->idOf($this->ishtarJobLine)]['activity'],
                    $nodes[$this->idOf($this->ishtarJobLine)]['jobs'],
                ],
                [$nodes['sale']['soldQuantity'], (float) $nodes['sale']['grossRevenue']],
            ],
        );
    }

    public function testAcceptedParticipantReadsTheSameChainAsTheOwner(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->chainUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                $this->idOf($this->morphiteLine),
                $this->idOf($this->nocxiumLine),
                $this->idOf($this->tritaniumLine),
                $this->idOf($this->ramJobLine),
                $this->idOf($this->ishtarJobLine),
                'sale',
            ],
            array_column($this->responseJson()['nodes'], 'id'),
        );
    }

    public function testPilotOfAnotherCorporationIsRefusedTheChain(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->chainUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testParticipantOfAnotherProjectIsRefusedTheChainOfAPrivateProjectHeDoesNotTakePartIn(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->chainUri($this->hecateBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testChainOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->chainUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function chainUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/chain';
    }

    private function nodeRows(): array
    {
        return array_map(
            static fn (array $node): array => [
                $node['id'],
                $node['stage'],
                $node['typeName'] ?? null,
                $node['status'],
                $node['costState'],
                isset($node['realCost']) ? (float) $node['realCost'] : null,
                $node['parentId'] ?? null,
            ],
            $this->responseJson()['nodes'],
        );
    }

    private function nodesById(): array
    {
        return array_column($this->responseJson()['nodes'], null, 'id');
    }
}
