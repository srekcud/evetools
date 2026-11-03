<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryStepJobMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectReadRouteNotFoundApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;
    use SeedsJitaOrderBooksAndCostIndices;

    private const string NOT_A_UUID = 'abc';

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
        $this->recordEsiRequestsInsteadOfSendingThem();
    }

    #[DataProvider('projectReadRoutes')]
    public function testProjectReadRouteOnAProjectOfAnotherUserIsNotFound(string $method, string $uriAfterTheProject): void
    {
        $this->client->loginUser($this->intruder, 'api');
        $this->request($method, $this->projectUri().$uriAfterTheProject, $method === 'POST' ? [] : null);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
        self::assertSame(0, $this->storedJobMatchCount());
        self::assertSame([], $this->esiRequests);
    }

    #[DataProvider('projectReadRoutes')]
    public function testProjectReadRouteOnAProjectIdentifierThatIsNotAUuidIsNotFound(string $method, string $uriAfterTheProject): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request($method, '/api/industry/projects/'.self::NOT_A_UUID.$uriAfterTheProject, $method === 'POST' ? [] : null);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public static function projectReadRoutes(): iterable
    {
        yield 'shopping list' => ['GET', '/shopping-list'];
        yield 'cost estimation' => ['GET', '/cost-estimation'];
        yield 'BPC kit' => ['GET', '/bpc-kit'];
        yield 'copy costs' => ['GET', '/copy-costs'];
        yield 'purchase suggestions' => ['GET', '/purchase-suggestions'];
        yield 'available jobs' => ['GET', '/available-jobs'];
        yield 'match jobs' => ['POST', '/match-jobs'];
    }

    private function storedJobMatchCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryStepJobMatch::class)->count([]);
    }
}
