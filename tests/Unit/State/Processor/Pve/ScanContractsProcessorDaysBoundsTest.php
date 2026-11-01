<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanContractsProcessor;
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

#[CoversClass(ScanContractsProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-294')]
final class ScanContractsProcessorDaysBoundsTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2113000002;
    private const SCOURGE_FURY_HEAVY_MISSILE = 2629;
    private const PURCHASE_INSIDE_SCANNED_DAYS = 820001;
    private const PURCHASE_OUTSIDE_SCANNED_DAYS = 820002;

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

    #[DataProvider('scannedDaysWithAPurchaseInsideAndOneOutside')]
    public function testScanProposesOnlyTheMarketPurchaseInsideTheScannedDays(Request $request, string $insideAge, string $outsideAge): void
    {
        $processor = $this->createProcessor($request, $insideAge, $outsideAge);

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [[self::PURCHASE_INSIDE_SCANNED_DAYS, 3200, 3776000.0]],
            array_map(
                static fn ($expense): array => [$expense->transactionId, $expense->quantity, $expense->price],
                $result->detectedExpenses,
            ),
        );
    }

    public static function scannedDaysWithAPurchaseInsideAndOneOutside(): iterable
    {
        yield 'days absent scans thirty days' => [new Request(), '-29 days', '-31 days'];
        yield 'one day' => [new Request(['days' => '1']), '-12 hours', '-36 hours'];
        yield 'three thousand six hundred fifty days' => [new Request(['days' => '3650']), '-3600 days', '-3700 days'];
    }

    private function createProcessor(Request $request, string $insideAge, string $outsideAge): ScanContractsProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithCharacter());

        $transactions = [
            $this->marketPurchase(self::PURCHASE_INSIDE_SCANNED_DAYS, 3200, 1180.0, new \DateTimeImmutable($insideAge)),
            $this->marketPurchase(self::PURCHASE_OUTSIDE_SCANNED_DAYS, 4100, 1215.0, new \DateTimeImmutable($outsideAge)),
        ];

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint): array {
            $this->esiRequests[] = $endpoint;

            return [];
        });
        $esiClient->method('get')->willReturnCallback(function (string $endpoint) use ($transactions): array {
            $this->esiRequests[] = $endpoint;

            return $endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/wallet/transactions/' ? $transactions : [];
        });

        $settings = new UserPveSettings();
        $settings->setAmmoTypeIds([self::SCOURGE_FURY_HEAVY_MISSILE]);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getImportedContractIds')->willReturn([]);
        $expenseRepository->method('getImportedTransactionIds')->willReturn([]);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ScanContractsProcessor(
            $security,
            $esiClient,
            $this->createStub(TokenManager::class),
            $expenseRepository,
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
        $character->method('getName')->willReturn('Ammo Buyer');

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function marketPurchase(int $transactionId, int $quantity, float $unitPrice, \DateTimeImmutable $date): array
    {
        return [
            'transaction_id' => $transactionId,
            'type_id' => self::SCOURGE_FURY_HEAVY_MISSILE,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'is_buy' => true,
            'date' => $date->format('c'),
        ];
    }
}
