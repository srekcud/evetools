<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ScanLootSalesProcessorCorpProjectsTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2112000001;
    private const OTHER_CORPORATION_MEMBER_IDS = [2112000077, 2112000078];
    private const CORPORATION_ID = 98000001;
    private const PROJECT_ID = 'a1b2c3d4-project';
    private const PROJECT_NAME = 'Tritanium Drive';
    private const RED_LOOT_PROJECT_ID = 'e5f6a7b8-red-loot';
    private const RED_LOOT_PROJECT_NAME = 'Rogue Drone Sweep';
    private const PYERITE_PROJECT_ID = 'c9d0e1f2-pyerite';
    private const PYERITE_PROJECT_NAME = 'Pyerite Haul';
    private const TRITANIUM = 34;
    private const ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const REWARD_PER_CONTRIBUTION = 12.5;
    private const RED_LOOT_REWARD_PER_CONTRIBUTION = 85000.0;
    private const CONTRIBUTED_QUANTITY = 1000;
    private const RED_LOOT_CONTRIBUTED_QUANTITY = 7;
    private const RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY = 5;
    private const RED_LOOT_MISSING_FROM_SDE_REWARD_PER_CONTRIBUTION = 64000.0;
    private const ACCESS_TOKEN = 'access-token-2112000001';
    private const COMPATIBILITY_DATE = '2025-12-16';
    private const PROJECTS_PATH = '/corporations/98000001/projects';

    private array $corporationProjectRequests = [];

    public function testCorpProjectContributionDetectedAsSaleWorthQuantityTimesRewardPerContribution(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor($this->corpProjectResponses($contributedAt));

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-abs(crc32(self::PROJECT_ID . '_' . self::EVE_CHARACTER_ID)), $sale->transactionId);
        $this->assertSame(0, $sale->contractId);
        $this->assertSame(self::PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::TRITANIUM, $sale->typeId);
        $this->assertSame('1000x Tritanium', $sale->typeName);
        $this->assertSame(1000, $sale->quantity);
        $this->assertSame(12500.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Pilot One', $sale->characterName);
        $this->assertSame('corp_project', $sale->source);
        $this->assertSame(self::PROJECT_NAME, $sale->projectName);
    }

    public function testCorpProjectRequestsSendCompatibilityDateAndCharacterBearerToken(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
        foreach ($this->corporationProjectRequests as $request) {
            $this->assertSame(['X-Compatibility-Date: ' . self::COMPATIBILITY_DATE], $request['headers']['x-compatibility-date'] ?? null, $request['url']);
            $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $request['headers']['authorization'] ?? null, $request['url']);
        }
    }

    public function testConstructorNoLongerTakesAnHttpClient(): void
    {
        $constructor = (new \ReflectionClass(ScanLootSalesProcessor::class))->getConstructor();
        $this->assertNotNull($constructor);

        $httpClientParameters = array_filter(
            $constructor->getParameters(),
            static fn (\ReflectionParameter $parameter): bool => (string) $parameter->getType() === HttpClientInterface::class,
        );
        $this->assertSame([], array_values(array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $httpClientParameters,
        )));
    }

    public function testCorpProjectRequestsTargetUnversionedOriginOfConfiguredEsiBaseUrl(): void
    {
        $processor = $this->createProcessor($this->corpProjectResponses(new \DateTimeImmutable('-2 days')));

        $processor->process(null, new Post());

        $this->assertSame([
            'https://esi.test/corporations/98000001/projects?limit=100&state=All',
            'https://esi.test/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], array_column($this->corporationProjectRequests, 'normalizedUrl'));
    }

    public function testRateLimitedCorpProjectsListIsReplayedOnceAndContributionStillDetected(): void
    {
        $responses = $this->corpProjectResponses(new \DateTimeImmutable('-2 days'));
        $firstProjectsPageKey = $this->projectsPageKey(null);
        $responses[$firstProjectsPageKey] = [$this->rateLimitedResponse(), $responses[$firstProjectsPageKey]];
        $processor = $this->createProcessor($responses);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(12500.0, $result->detectedSales[0]->price);
        $this->assertSame(1000, $result->detectedSales[0]->quantity);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
    }

    public function testUnavailableCorpProjectsListYieldsNoProjectSaleAndNoScannedProject(): void
    {
        $processor = $this->createProcessor([
            $this->projectsPageKey(null) => new MockResponse('{"error":"Internal error"}', ['http_code' => 500]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(0, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame(['/corporations/98000001/projects?limit=100&state=All'], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testRedLootDeliveredToACompletedProjectModifiedTenDaysAgoIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-11 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Completed', new \DateTimeImmutable('-10 days')),
            ]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-abs(crc32(self::RED_LOOT_PROJECT_ID . '_' . self::EVE_CHARACTER_ID)), $sale->transactionId);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame('7x Rogue Drone Infestation Data', $sale->typeName);
        $this->assertSame(7, $sale->quantity);
        $this->assertSame(595000.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Pilot One', $sale->characterName);
        $this->assertSame(self::RED_LOOT_PROJECT_NAME, $sale->projectName);
    }

    #[Group('issue-114')]
    public function testProjectOnlyOnTheSecondPageOfTheProjectListIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-4 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages(
                [$this->projectRecord(self::PYERITE_PROJECT_ID, self::PYERITE_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day'))],
                [$this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-3 days'))],
            ),
            ...$this->contributorPages(self::PYERITE_PROJECT_ID, [$this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[0], 'Hauler Seventy Seven', 40000)]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(2, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame('7x Rogue Drone Infestation Data', $result->detectedSales[0]->typeName);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-2&limit=100&state=All',
            '/corporations/98000001/projects/c9d0e1f2-pyerite/contributors?limit=100',
            '/corporations/98000001/projects/c9d0e1f2-pyerite/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testCharacterOnlyOnTheSecondPageOfContributorsIsDetected(): void
    {
        $contributedAt = new \DateTimeImmutable('-5 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(
                self::RED_LOOT_PROJECT_ID,
                [
                    $this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[0], 'Hauler Seventy Seven', 12),
                    $this->contributorRecord(self::OTHER_CORPORATION_MEMBER_IDS[1], 'Hauler Seventy Eight', 3),
                ],
                [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)],
            ),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(7, $result->detectedSales[0]->quantity);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame('Pilot One', $result->detectedSales[0]->characterName);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-2&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testDeletedProjectAndProjectModifiedBeforeTheWindowAreSkippedWithoutContributorsRequest(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PYERITE_PROJECT_ID, self::PYERITE_PROJECT_NAME, 'Expired', new \DateTimeImmutable('-45 days')),
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Deleted', new \DateTimeImmutable('-1 day')),
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-2 days')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, self::CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(12500.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/a1b2c3d4-project',
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-114')]
    public function testProjectReturnedOnTwoPagesOfTheProjectListYieldsASingleSale(): void
    {
        $contributedAt = new \DateTimeImmutable('-6 days');
        $redLootProject = $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Completed', new \DateTimeImmutable('-3 days'));
        $processor = $this->createProcessor([
            ...$this->projectListPages([$redLootProject], [$redLootProject]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_CONTRIBUTED_QUANTITY, $contributedAt),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertCount(1, $result->detectedSales);
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $result->detectedSales[0]->projectId);
        $this->assertSame(595000.0, $result->detectedSales[0]->price);
        $this->assertSame([
            '/corporations/98000001/projects?limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-1&limit=100&state=All',
            '/corporations/98000001/projects?before=projects-cursor-2&limit=100&state=All',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contributors?before=contributors-cursor-1&limit=100',
            '/corporations/98000001/projects/e5f6a7b8-red-loot',
            '/corporations/98000001/projects/e5f6a7b8-red-loot/contribution/2112000001',
        ], $this->requestedPaths());
    }

    #[Group('issue-115')]
    public function testContributionOfLootMissingFromTheSdeIsDetectedWithTheExplicitLabel(): void
    {
        $contributedAt = new \DateTimeImmutable('-3 days');
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::RED_LOOT_PROJECT_ID, self::RED_LOOT_PROJECT_NAME, 'Active', new \DateTimeImmutable('-2 days')),
            ]),
            ...$this->contributorPages(self::RED_LOOT_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::RED_LOOT_PROJECT_ID, self::ROGUE_DRONE_INFESTATION_DATA, self::RED_LOOT_MISSING_FROM_SDE_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::RED_LOOT_PROJECT_ID, self::EVE_CHARACTER_ID, self::RED_LOOT_MISSING_FROM_SDE_CONTRIBUTED_QUANTITY, $contributedAt),
        ], [self::TRITANIUM => 'Tritanium']);

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(self::RED_LOOT_PROJECT_ID, $sale->projectId);
        $this->assertSame(PveIncomeType::CorpProject->value, $sale->type);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame('5x type 60459 (name missing from the SDE)', $sale->typeName);
        $this->assertSame(5, $sale->quantity);
        $this->assertSame(320000.0, $sale->price);
        $this->assertSame($contributedAt->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame(self::RED_LOOT_PROJECT_NAME, $sale->projectName);
    }

    private function corpProjectResponses(\DateTimeImmutable $contributedAt): array
    {
        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', self::CONTRIBUTED_QUANTITY)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, self::CONTRIBUTED_QUANTITY, $contributedAt),
        ];
    }

    private function projectListPages(array ...$pages): array
    {
        $responses = [];
        $before = null;
        foreach ($pages as $pageIndex => $projects) {
            $nextBefore = 'projects-cursor-' . ($pageIndex + 1);
            $responses[$this->projectsPageKey($before)] = $this->jsonResponse([
                'projects' => $projects,
                'cursor' => ['before' => $nextBefore, 'after' => 'projects-after-' . ($pageIndex + 1)],
            ]);
            $before = $nextBefore;
        }
        $responses[$this->projectsPageKey($before)] = $this->jsonResponse([
            'projects' => [],
            'cursor' => ['after' => 'projects-after-end'],
        ]);

        return $responses;
    }

    private function contributorPages(string $projectId, array ...$pages): array
    {
        $responses = [];
        $before = null;
        foreach ($pages as $pageIndex => $contributors) {
            $nextBefore = 'contributors-cursor-' . ($pageIndex + 1);
            $responses[$this->contributorsPageKey($projectId, $before)] = $this->jsonResponse([
                'contributors' => $contributors,
                'cursor' => ['before' => $nextBefore, 'after' => 'contributors-after-' . ($pageIndex + 1)],
            ]);
            $before = $nextBefore;
        }
        $responses[$this->contributorsPageKey($projectId, $before)] = $this->jsonResponse([
            'contributors' => [],
            'cursor' => ['after' => 'contributors-after-end'],
        ]);

        return $responses;
    }

    private function projectDetails(string $projectId, int $deliveredTypeId, float $rewardPerContribution): array
    {
        return [
            self::PROJECTS_PATH . '/' . $projectId => $this->jsonResponse([
                'configuration' => ['deliver_item' => ['items' => [['type_id' => $deliveredTypeId]]]],
                'contribution' => ['reward_per_contribution' => $rewardPerContribution],
            ]),
        ];
    }

    private function contribution(string $projectId, int $characterId, int $contributedQuantity, \DateTimeImmutable $contributedAt): array
    {
        return [
            self::PROJECTS_PATH . '/' . $projectId . '/contribution/' . $characterId => $this->jsonResponse([
                'contributed' => $contributedQuantity,
                'last_modified' => $contributedAt->format('c'),
            ]),
        ];
    }

    private function projectRecord(string $projectId, string $projectName, string $state, \DateTimeImmutable $lastModified): array
    {
        return [
            'id' => $projectId,
            'name' => $projectName,
            'state' => $state,
            'last_modified' => $lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function contributorRecord(int $characterId, string $characterName, int $contributedQuantity): array
    {
        return ['id' => $characterId, 'name' => $characterName, 'contributed' => $contributedQuantity];
    }

    private function projectsPageKey(?string $before): string
    {
        return self::PROJECTS_PATH . '?' . ($before !== null ? 'before=' . $before . '&' : '') . 'limit=100&state=All';
    }

    private function contributorsPageKey(string $projectId, ?string $before): string
    {
        return self::PROJECTS_PATH . '/' . $projectId . '/contributors?' . ($before !== null ? 'before=' . $before . '&' : '') . 'limit=100';
    }

    private function requestedPaths(): array
    {
        return array_column($this->corporationProjectRequests, 'key');
    }

    private function createProcessor(
        array $corpProjectResponsesByKey,
        array $knownTypeNamesById = [self::TRITANIUM => 'Tritanium', self::ROGUE_DRONE_INFESTATION_DATA => 'Rogue Drone Infestation Data'],
    ): ScanLootSalesProcessor {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn(self::ACCESS_TOKEN);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithCorporationCharacter());

        $settings = new UserPveSettings();
        $settings->setLootTypeIds([self::TRITANIUM, self::ROGUE_DRONE_INFESTATION_DATA]);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedTransactionIds')->willReturn([]);

        $typesById = array_map(fn (string $typeName): InvType => $this->createInvType($typeName), $knownTypeNamesById);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $typesById[$typeId] ?? null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new ScanLootSalesProcessor(
            security: $security,
            esiClient: new EsiClient($this->createSimulatedEsi($corpProjectResponsesByKey), new ArrayAdapter(), $tokenManager, 'https://esi.test/latest', new NullLogger()),
            tokenManager: $tokenManager,
            incomeRepository: $incomeRepository,
            settingsRepository: $settingsRepository,
            invTypeRepository: $invTypeRepository,
            requestStack: $requestStack,
            logger: new NullLogger(),
            cache: new ArrayAdapter(),
        );
    }

    private function createInvType(string $typeName): InvType
    {
        $invType = $this->createStub(InvType::class);
        $invType->method('getTypeName')->willReturn($typeName);

        return $invType;
    }

    private function createSimulatedEsi(array $corpProjectResponsesByKey): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$corpProjectResponsesByKey): MockResponse {
            $origin = (string) preg_replace('#^(https://[^/]+).*$#', '$1', $url);
            $key = $this->requestKey($url);

            if (str_starts_with($key, '/corporations/')) {
                $this->corporationProjectRequests[] = [
                    'url' => $url,
                    'normalizedUrl' => $origin . $key,
                    'key' => $key,
                    'headers' => $options['normalized_headers'] ?? [],
                ];
                $plannedResponse = $corpProjectResponsesByKey[$key] ?? null;
                if (is_array($plannedResponse)) {
                    $response = array_shift($corpProjectResponsesByKey[$key]);
                } else {
                    $response = $plannedResponse;
                    unset($corpProjectResponsesByKey[$key]);
                }
                if ($response === null) {
                    $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
                }

                return $response;
            }

            if (str_starts_with($key, '/characters/')) {
                return $this->jsonResponse([]);
            }

            $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
        });
    }

    private function requestKey(string $url): string
    {
        $path = (string) preg_replace('#^/latest#', '', (string) parse_url($url, PHP_URL_PATH));
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return $path;
        }
        parse_str($query, $parameters);
        ksort($parameters);

        return $path . '?' . http_build_query($parameters);
    }

    private function jsonResponse(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    private function rateLimitedResponse(): MockResponse
    {
        return new MockResponse('{"error":"rate limited"}', [
            'http_code' => 429,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => '0',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    private function createUserWithCorporationCharacter(): User
    {
        $token = $this->createStub(EveToken::class);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('Pilot One');

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }
}
