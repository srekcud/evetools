<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootContractsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(ScanLootContractsProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-294')]
final class ScanLootContractsProcessorDaysBoundsTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2113000003;
    private const THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS = 19402;
    private const ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const LOOT_CONTRACT_INSIDE_SCANNED_DAYS = 610001;
    private const LOOT_CONTRACT_OUTSIDE_SCANNED_DAYS = 610002;

    private array $esiRequests = [];

    #[DataProvider('daysOutsideOneToThreeThousandSixHundredFifty')]
    public function testDaysOutsideOneToThreeThousandSixHundredFiftyIsABadRequestWithoutAnyEsiRequest(string $days): void
    {
        $processor = $this->createProcessor(new Request(['days' => $days]), '-2 days', '-40 days');

        try {
            $processor->process(null, new Post());
            $this->fail("days={$days} was accepted");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('days must be an integer between 1 and 3650', $exception->getMessage());
        }

        $this->assertSame([], $this->esiRequests);
    }

    public static function daysOutsideOneToThreeThousandSixHundredFifty(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'one more than ten years' => ['3651'];
        yield 'far beyond ten years' => ['100000'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['7.5'];
    }

    #[DataProvider('scannedDaysWithALootContractInsideAndOneOutside')]
    public function testScanProposesOnlyTheLootContractCompletedInsideTheScannedDays(Request $request, string $insideAge, string $outsideAge): void
    {
        $processor = $this->createProcessor($request, $insideAge, $outsideAge);

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [[self::LOOT_CONTRACT_INSIDE_SCANNED_DAYS, 7, 2450000.0]],
            array_map(
                static fn ($detected): array => [$detected->contractId, $detected->totalQuantity, $detected->suggestedPrice],
                $result->detectedContracts,
            ),
        );
    }

    public static function scannedDaysWithALootContractInsideAndOneOutside(): iterable
    {
        yield 'days absent scans thirty days' => [new Request(), '-29 days', '-31 days'];
        yield 'one day' => [new Request(['days' => '1']), '-12 hours', '-36 hours'];
        yield 'three thousand six hundred fifty days' => [new Request(['days' => '3650']), '-3600 days', '-3700 days'];
    }

    private function createProcessor(Request $request, string $insideAge, string $outsideAge): ScanLootContractsProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithCharacter());

        $contracts = [
            $this->finishedLootContract(self::LOOT_CONTRACT_INSIDE_SCANNED_DAYS, 2450000.0, new \DateTimeImmutable($insideAge)),
            $this->finishedLootContract(self::LOOT_CONTRACT_OUTSIDE_SCANNED_DAYS, 1730000.0, new \DateTimeImmutable($outsideAge)),
        ];
        $itemsByContractId = [
            self::LOOT_CONTRACT_INSIDE_SCANNED_DAYS => [['type_id' => self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS, 'quantity' => 7, 'is_included' => true]],
            self::LOOT_CONTRACT_OUTSIDE_SCANNED_DAYS => [['type_id' => self::ROGUE_DRONE_INFESTATION_DATA, 'quantity' => 5, 'is_included' => true]],
        ];

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint) use ($contracts): array {
            $this->esiRequests[] = $endpoint;

            return $contracts;
        });
        $esiClient->method('get')->willReturnCallback(function (string $endpoint) use ($itemsByContractId): array {
            $this->esiRequests[] = $endpoint;
            foreach ($itemsByContractId as $contractId => $items) {
                if ($endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/contracts/' . $contractId . '/items/') {
                    return $items;
                }
            }

            return [];
        });

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedContractIds')->willReturn([]);

        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ScanLootContractsProcessor(
            $security,
            $esiClient,
            $this->createStub(TokenManager::class),
            $incomeRepository,
            $settingsRepository,
            $invTypeRepository,
            $requestStack,
        );
    }

    private function createUserWithCharacter(): User
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getName')->willReturn('Loot Seller');

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function finishedLootContract(int $contractId, float $price, \DateTimeImmutable $completedAt): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => self::EVE_CHARACTER_ID,
            'price' => $price,
            'date_issued' => $completedAt->modify('-1 day')->format('c'),
            'date_completed' => $completedAt->format('c'),
        ];
    }
}
