<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectProduct;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-336')]
final class ProjectCockpitApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->ishtarBatch->addItem((new IndustryProjectProduct())->setTypeId(self::ISHTAR_TYPE_ID)->setTypeName('Ishtar')->setRuns(self::ISHTAR_RUNS));
        $this->hecateBatch->setPrivate(true);
        $this->em->flush();
    }

    public function testAcceptedMemberReadsTheFivePhasesAndIsAskedToSourceTheMaterialWithTheLargestRemainder(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/cockpit');

        self::assertResponseStatusCodeSame(200);
        $cockpit = $this->responseJson();
        self::assertSame(
            [
                'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
                'status' => 'in_progress',
                'phases' => [
                    ['key' => 'prepare', 'state' => 'done', 'counter' => ['done' => 1, 'total' => 1], 'blockedReason' => null],
                    ['key' => 'supply', 'state' => 'blocked', 'counter' => ['done' => 0, 'total' => 3], 'blockedReason' => ['code' => 'materials_missing', 'count' => 3]],
                    ['key' => 'produce', 'state' => 'current', 'counter' => ['done' => 1, 'total' => 2], 'blockedReason' => null],
                    ['key' => 'sell', 'state' => 'upcoming', 'counter' => ['done' => 2, 'total' => 2], 'blockedReason' => null],
                    ['key' => 'close', 'state' => 'upcoming', 'counter' => ['done' => 0, 'total' => 1], 'blockedReason' => null],
                ],
                'nextAction' => [
                    'code' => 'source_material',
                    'blocked' => true,
                    'tab' => 'materials',
                    'targetStatus' => null,
                    'bomLineId' => $this->idOf($this->tritaniumLine),
                    'typeId' => self::TRITANIUM_TYPE_ID,
                    'typeName' => 'Tritanium',
                    'quantity' => 12_000,
                ],
            ],
            [
                'projectId' => $cockpit['projectId'] ?? null,
                'status' => $cockpit['status'] ?? null,
                'phases' => $cockpit['phases'] ?? null,
                'nextAction' => $cockpit['nextAction'] ?? null,
            ],
        );
    }

    public function testOutsiderIsRefusedTheCockpit(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/cockpit');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testParticipantOfAnotherProjectIsRefusedThePrivateProjectCockpitWithoutAnyOfItsData(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->projectUri($this->hecateBatch).'/cockpit');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        self::assertSame(
            [false, false, false],
            [
                array_key_exists('phases', $this->responseJson()),
                array_key_exists('nextAction', $this->responseJson()),
                str_contains((string) $this->client->getResponse()->getContent(), 'Tritanium'),
            ],
        );
    }
}
