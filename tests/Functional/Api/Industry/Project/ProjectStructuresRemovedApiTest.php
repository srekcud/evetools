<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1345')]
final class ProjectStructuresRemovedApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testStructuresRouteOfAnExistingProjectIsNoLongerServed(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', '/api/industry/projects/'.$this->ishtarBatch->getId()->toRfc4122().'/structures');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWorkStationsRouteReplacingItIsStillServedToAnAcceptedMember(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', '/api/industry/projects/'.$this->ishtarBatch->getId()->toRfc4122().'/work-stations');

        self::assertResponseStatusCodeSame(200);
    }
}
