<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryStockpileTarget;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-352')]
final class ProjectSurplusApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int TRITANIUM_CONTRIBUTED = 12_500;
    private const float TRITANIUM_CONTRIBUTED_VALUE = 53_125.0;
    private const int TRITANIUM_SURPLUS = 500;
    private const float TRITANIUM_UNIT_PRICE = 4.25;
    private const float TRITANIUM_SURPLUS_VALUE = 2_125.0;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->addContribution($this->ownerMembership, $this->tritaniumLine, ContributionType::Material, self::TRITANIUM_CONTRIBUTED, self::TRITANIUM_CONTRIBUTED_VALUE, ContributionStatus::Approved);
        $this->addContribution($this->memberMembership, $this->nocxiumLine, ContributionType::Material, 700, 560_000.0, ContributionStatus::Pending);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();
    }

    public function testAcceptedMemberReadsTheMaterialSurplusOfACompletedProjectValuedAtTheContributionPrice(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->surplusUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                'id' => $this->ishtarBatch->getId()->toRfc4122(),
                'lines' => [
                    [
                        'contributedQuantity' => self::TRITANIUM_CONTRIBUTED,
                        'lineId' => $this->idOf($this->tritaniumLine),
                        'requiredQuantity' => 12_000,
                        'surplusQuantity' => self::TRITANIUM_SURPLUS,
                        'surplusValue' => self::TRITANIUM_SURPLUS_VALUE,
                        'typeId' => self::TRITANIUM_TYPE_ID,
                        'typeName' => 'Tritanium',
                        'unitPrice' => self::TRITANIUM_UNIT_PRICE,
                    ],
                ],
                'totalValue' => self::TRITANIUM_SURPLUS_VALUE,
            ],
            $this->canonical($this->responseJson()),
        );
    }

    public function testContributionWithoutValueKeepsItsNullKeysInsteadOfZero(): void
    {
        $this->addContribution($this->ownerMembership, $this->morphiteLine, ContributionType::Material, 160, 2_960_000.0, ContributionStatus::Approved)->setEstimatedValue(null);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->surplusUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertNull($resource['totalValue']);
        $morphite = array_values(array_filter($resource['lines'], fn (array $line): bool => $line['lineId'] === $this->idOf($this->morphiteLine)));
        self::assertCount(1, $morphite);
        self::assertNull($morphite[0]['unitPrice']);
        self::assertNull($morphite[0]['surplusValue']);
    }

    public function testProjectThatIsNotCompletedHasAnEmptySurplus(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::InProgress);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->surplusUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['id' => $this->ishtarBatch->getId()->toRfc4122(), 'lines' => [], 'totalValue' => 0.0],
            $this->canonical($this->responseJson()),
        );
    }

    public function testReadingTheSurplusWritesNothingAndNeverTouchesTheStockpile(): void
    {
        $contributionsBefore = $this->em->getRepository(IndustryProjectContribution::class)->count([]);
        $stockpileTargetsBefore = $this->em->getRepository(IndustryStockpileTarget::class)->count([]);
        $fulfilledBefore = $this->tritaniumLine->getFulfilledQuantity();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->surplusUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        self::assertSame($contributionsBefore, $this->em->getRepository(IndustryProjectContribution::class)->count([]));
        self::assertSame($stockpileTargetsBefore, $this->em->getRepository(IndustryStockpileTarget::class)->count([]));
        self::assertSame($fulfilledBefore, $this->em->find($this->tritaniumLine::class, $this->tritaniumLine->getId())->getFulfilledQuantity());
    }

    public function testPilotWhoDoesNotTakePartInTheProjectIsRefusedItsSurplus(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->surplusUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testSurplusOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->surplusUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function surplusUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/surplus';
    }

    private function canonical(array $payload): array
    {
        if (!array_is_list($payload)) {
            ksort($payload);
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->canonical($value) : $value, $payload);
    }
}
