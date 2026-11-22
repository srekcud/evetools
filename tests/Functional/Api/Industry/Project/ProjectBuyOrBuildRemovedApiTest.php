<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1328')]
final class ProjectBuyOrBuildRemovedApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testBuyOrBuildRouteOfAnExistingProjectIsNoLongerServed(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', '/api/industry/projects/'.$this->ishtarBatch->getId()->toRfc4122().'/buy-or-build');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSourcingDecisionsRouteReplacingItIsStillServedToAnAcceptedMember(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', '/api/industry/projects/'.$this->ishtarBatch->getId()->toRfc4122().'/sourcing-decisions');

        self::assertResponseStatusCodeSame(200);
    }
}
