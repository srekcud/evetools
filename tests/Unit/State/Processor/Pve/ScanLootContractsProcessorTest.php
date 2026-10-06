<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootContractsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ScanLootContractsProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ScanLootContractsProcessorTest extends TestCase
{
    private const EVE_CHARACTER_ID = 12345;

    private Security&Stub $security;
    private EsiClient&Stub $esiClient;
    private PveIncomeRepository&Stub $incomeRepository;
    private UserPveSettingsRepository&Stub $settingsRepository;
    private ScanLootContractsProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->incomeRepository = $this->createStub(PveIncomeRepository::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $this->processor = new ScanLootContractsProcessor(
            $this->security,
            $this->esiClient,
            $this->createStub(TokenManager::class),
            $this->incomeRepository,
            $this->settingsRepository,
            $this->createStub(InvTypeRepository::class),
            $requestStack,
        );
    }

    // ===========================================
    // Contracts pagination (issue #11)
    // ===========================================

    public function testLootContractOnSecondContractsPageDetected(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $lootTypeId = UserPveSettings::PVE_LOOT_TYPE_IDS[0];
        $contractsPage1 = [$this->finishedLootContract(600001)];
        $contractsPage2 = [$this->finishedLootContract(600002)];

        $this->esiClient->method('get')->willReturnCallback(
            function (string $endpoint) use ($contractsPage1, $lootTypeId): array {
                if (str_ends_with($endpoint, '/items/')) {
                    return [['type_id' => $lootTypeId, 'quantity' => 4, 'is_included' => true]];
                }
                if ($endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/contracts/') {
                    // ESI without page param returns page 1 only
                    return $contractsPage1;
                }
                return [];
            }
        );
        $this->esiClient->method('getPaginated')->willReturn([...$contractsPage1, ...$contractsPage2]);

        $result = $this->processor->process(null, new Post());

        $detectedContractIds = array_map(
            static fn ($detected) => $detected->contractId,
            $result->detectedContracts,
        );
        $this->assertSame([600001, 600002], $detectedContractIds);
        $this->assertSame(2, $result->scannedContracts);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    /** @return array<string, mixed> */
    private function finishedLootContract(int $contractId): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => self::EVE_CHARACTER_ID,
            'price' => 0.0,
            'date_issued' => (new \DateTimeImmutable('-3 days'))->format('c'),
            'date_completed' => (new \DateTimeImmutable('-2 days'))->format('c'),
        ];
    }
}
