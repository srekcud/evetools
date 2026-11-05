<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Tests\Functional\Api\GroupIndustry\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-362')]
final class ProjectStepRoutesRemovedApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string STEP_IDENTIFIER = '0f6c1c52-7d0b-4a53-9a6e-3d1b8f0d2c11';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    #[DataProvider('removedStepRoutes')]
    public function testRemovedStepRouteOnTheProjectOfTheOwnerMatchesNoRoute(string $method, string $uriAfterTheProject): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request($method, '/api/industry/projects/'.$this->ishtarBatch->getId()?->toRfc4122().$uriAfterTheProject, \in_array($method, ['POST', 'PATCH'], true) ? [] : null);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->client->getRequest()->attributes->get('_route'));
    }

    public static function removedStepRoutes(): iterable
    {
        yield 'create step' => ['POST', '/steps'];
        yield 'update step' => ['PATCH', '/steps/'.self::STEP_IDENTIFIER];
        yield 'delete step' => ['DELETE', '/steps/'.self::STEP_IDENTIFIER];
        yield 'split step' => ['POST', '/steps/'.self::STEP_IDENTIFIER.'/split'];
        yield 'merge steps' => ['POST', '/steps/'.self::STEP_IDENTIFIER.'/merge'];
        yield 'shopping list' => ['GET', '/shopping-list'];
        yield 'cost estimation' => ['GET', '/cost-estimation'];
        yield 'copy costs' => ['GET', '/copy-costs'];
        yield 'BPC kit' => ['GET', '/bpc-kit'];
    }
}
