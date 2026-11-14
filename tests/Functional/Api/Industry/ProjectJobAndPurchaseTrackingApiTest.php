<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectPurchase;
use App\Enum\ParticipantRole;
use App\Tests\Functional\Api\Industry\Project\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-362')]
final class ProjectJobAndPurchaseTrackingApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_STATION_ID = 60_003_760;
    private const int RAM_BUY_ID = 8_362_001;
    private const int TRITANIUM_BUY_ID = 8_362_002;
    private const int ISHTAR_JOB_ID = 7_362_701;
    private const float RAM_UNIT_PRICE = 3_040.5;

    private IndustryProjectPurchase $tritaniumPurchase;
    private IndustryProjectPurchase $hecateTritaniumPurchase;
    private IndustryProjectJobMatch $ishtarJobMatch;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->memberMembership->setRole(ParticipantRole::Admin);
        $this->tritaniumPurchase = $this->manualPurchaseOn($this->tritaniumLine, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
        $this->hecateTritaniumPurchase = $this->manualPurchaseOn($this->hecateTritaniumLine, self::TRITANIUM_TYPE_ID, 5_000, 4.3);
        $this->ishtarJobMatch = (new IndustryProjectJobMatch())
            ->setEsiJobId(self::ISHTAR_JOB_ID)
            ->setRuns(self::ISHTAR_RUNS)
            ->setStatus('active')
            ->setCost(1_482_350.25)
            ->setCharacterName('Aria Solberg')
            ->setManual(true);
        $this->ishtarJobLine->addJobMatch($this->ishtarJobMatch);
        $this->em->flush();
    }

    public function testOwnerRecordsAManualPurchaseOnTheRamLineAndServesTheLineByItsQuantity(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->lineUri($this->ramJobLine).'/purchases', [
            'typeId' => self::RAM_STARSHIP_TECH_TYPE_ID,
            'quantity' => 50,
            'unitPrice' => self::RAM_UNIT_PRICE,
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->ramJobLine), self::RAM_STARSHIP_TECH_TYPE_ID, 50, 3_040.5, 152_025.0, 'manual'],
            [$response['bomLineId'], $response['typeId'], $response['quantity'], $response['unitPrice'], $response['totalPrice'], $response['source']],
        );
        self::assertSame([[self::RAM_STARSHIP_TECH_TYPE_ID, 50, 152_025.0]], $this->storedPurchasesOf($this->ramJobLine));
        self::assertSame(50, $this->storedFulfilledQuantityOf($this->ramJobLine));
    }

    public function testOwnerDeletesThePurchaseOfTheTritaniumLine(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->ishtarUri().'/purchases/'.$this->tritaniumPurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedPurchasesOf($this->tritaniumLine));
    }

    public function testDeletingThePurchaseOfAnotherProjectThroughThisProjectIsNotFoundAndKeepsIt(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->ishtarUri().'/purchases/'.$this->hecateTritaniumPurchase->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Purchase not found'], $this->errorDetail());
        self::assertSame([[self::TRITANIUM_TYPE_ID, 5_000, 21_500.0]], $this->storedPurchasesOf($this->hecateTritaniumLine));
    }

    public function testOwnerUnlinksTheJobOfTheIshtarLine(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->ishtarUri().'/job-matches/'.$this->ishtarJobMatch->getId()?->toRfc4122());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedJobIdsOf($this->ishtarJobLine));
    }

    public function testPurchaseSuggestionsLeaveOutTheBuysMadeBeforeTheCreationOfTheProject(): void
    {
        $ownerCharacter = $this->owner->getMainCharacter();
        self::assertNotNull($ownerCharacter);
        $this->walletBuy($ownerCharacter, self::RAM_BUY_ID, self::RAM_STARSHIP_TECH_TYPE_ID, 50, self::RAM_UNIT_PRICE, '-2 days');
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->ishtarUri().'/purchase-suggestions');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->responseJson()['totalCount']);
    }

    public function testPurchaseSuggestionsOfTheOwnerListTheBuysOfTheComponentAndMaterialLines(): void
    {
        $ownerCharacter = $this->owner->getMainCharacter();
        self::assertNotNull($ownerCharacter);
        $this->walletBuy($ownerCharacter, self::RAM_BUY_ID, self::RAM_STARSHIP_TECH_TYPE_ID, 50, self::RAM_UNIT_PRICE);
        $this->walletBuy($ownerCharacter, self::TRITANIUM_BUY_ID, self::TRITANIUM_TYPE_ID, 1_000, 4.4);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->ishtarUri().'/purchase-suggestions');

        self::assertResponseStatusCodeSame(200);
        $suggestions = $this->responseJson();
        $suggestionList = $suggestions['suggestions'];
        usort($suggestionList, static fn (array $first, array $second): int => $first['transactionId'] <=> $second['transactionId']);
        self::assertSame(
            [2, [
                [self::RAM_BUY_ID, self::RAM_STARSHIP_TECH_TYPE_ID, 50, 152_025.0, 'Aria Solberg', false],
                [self::TRITANIUM_BUY_ID, self::TRITANIUM_TYPE_ID, 1_000, 4_400.0, 'Aria Solberg', false],
            ]],
            [
                $suggestions['totalCount'],
                array_map(static fn (array $suggestion): array => [
                    $suggestion['transactionId'],
                    $suggestion['typeId'],
                    $suggestion['quantity'],
                    $suggestion['totalPrice'],
                    $suggestion['characterName'],
                    $suggestion['alreadyLinked'],
                ], $suggestionList),
            ],
        );
    }

    #[Group('issue-916')]
    public function testPurchaseSuggestionsCarryTheWalletReadingOfEachCharacterOfTheOwnerEvenWithoutAnyBuy(): void
    {
        $ownerMain = $this->owner->getMainCharacter();
        self::assertNotNull($ownerMain);
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes(['esi-wallet.read_character_wallet.v1']);
        $ownerMain->setEveToken($token);
        $this->em->persist($token);
        $this->em->persist(
            (new CharacterWalletReading())
                ->setCharacter($ownerMain)
                ->setReadAt(new \DateTimeImmutable('2026-11-13 20:04:10 UTC'))
                ->setNextReadAt(new \DateTimeImmutable('2026-11-13 21:04:00 UTC')),
        );
        $ownerAlt = (new Character())
            ->setEveCharacterId(2_116_916_001)
            ->setName('Aria Alt')
            ->setCorporationId($ownerMain->getCorporationId())
            ->setCorporationName($ownerMain->getCorporationName());
        $this->owner->addCharacter($ownerAlt);
        $this->em->persist($ownerAlt);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->ishtarUri().'/purchase-suggestions');

        self::assertResponseStatusCodeSame(200);
        $response = $this->responseJson();
        self::assertArrayHasKey('walletReadings', $response);
        self::assertSame([0, []], [$response['totalCount'], $response['suggestions']]);
        self::assertSame(
            [
                ['Aria Solberg', 'read', (new \DateTimeImmutable('2026-11-13 20:04:10 UTC'))->getTimestamp(), (new \DateTimeImmutable('2026-11-13 21:04:00 UTC'))->getTimestamp()],
                ['Aria Alt', 'no_token', null, null],
            ],
            array_map(static function (array $reading): array {
                self::assertArrayHasKey('readAt', $reading);
                self::assertArrayHasKey('nextReadAt', $reading);

                return [
                    $reading['characterName'],
                    $reading['status'],
                    $reading['readAt'] === null ? null : (new \DateTimeImmutable($reading['readAt']))->getTimestamp(),
                    $reading['nextReadAt'] === null ? null : (new \DateTimeImmutable($reading['nextReadAt']))->getTimestamp(),
                ];
            }, $response['walletReadings']),
        );
    }

    #[DataProvider('ownerOnlyTrackingOperations')]
    public function testAcceptedAdminWhoIsNotTheOwnerIsDeniedTheTrackingOfJobs(string $uriAfterTheProject, array $body): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->ishtarUri().str_replace(
            ['{ishtarLineId}', '{ramLineId}'],
            [$this->idOf($this->ishtarJobLine), $this->idOf($this->ramJobLine)],
            $uriAfterTheProject,
        ), $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can perform this action'], $this->errorDetail());
        self::assertSame([self::ISHTAR_JOB_ID], $this->storedJobIdsOf($this->ishtarJobLine));
    }

    public static function ownerOnlyTrackingOperations(): iterable
    {
        yield 'match the jobs' => ['/match-jobs', []];
        yield 'link a job' => ['/bom/{ishtarLineId}/link-job', ['esiJobId' => 7_362_799]];
    }

    #[Group('issue-981')]
    public function testAcceptedAdminWhoIsNotTheOwnerIsDeniedAManualPurchase(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->lineUri($this->ramJobLine).'/purchases', ['typeId' => self::RAM_STARSHIP_TECH_TYPE_ID, 'quantity' => 50, 'unitPrice' => self::RAM_UNIT_PRICE]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can add a manual purchase'], $this->errorDetail());
        self::assertSame([], $this->storedPurchasesOf($this->ramJobLine));
    }

    private function ishtarUri(): string
    {
        return '/api/industry/projects/'.$this->ishtarBatch->getId()?->toRfc4122();
    }

    private function lineUri(IndustryProjectBomLine $line): string
    {
        return $this->ishtarUri().'/bom/'.$this->idOf($line);
    }

    private function manualPurchaseOn(IndustryProjectBomLine $line, int $typeId, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
        $line->addPurchase($purchase);

        return $purchase;
    }

    private function walletBuy(Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice, string $date = 'now'): void
    {
        $this->em->persist((new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(self::JITA_STATION_ID)
            ->setDate(new \DateTimeImmutable($date)));
    }

    private function storedPurchasesOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();
        $purchases = $this->em->getRepository(IndustryProjectPurchase::class)->findBy(['bomLine' => $line->getId()], ['createdAt' => 'ASC']);

        return array_map(
            static fn (IndustryProjectPurchase $purchase): array => [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getTotalPrice()],
            $purchases,
        );
    }

    private function storedJobIdsOf(IndustryProjectBomLine $line): array
    {
        $this->em->clear();
        $matches = $this->em->getRepository(IndustryProjectJobMatch::class)->findBy(['bomLine' => $line->getId()], ['esiJobId' => 'ASC']);

        return array_map(static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(), $matches);
    }

    private function storedFulfilledQuantityOf(IndustryProjectBomLine $line): int
    {
        $this->em->clear();
        $storedLine = $this->em->find(IndustryProjectBomLine::class, $line->getId());
        self::assertNotNull($storedLine);

        return $storedLine->getFulfilledQuantity();
    }
}
