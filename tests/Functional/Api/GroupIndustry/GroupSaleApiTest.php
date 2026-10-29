<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\GroupIndustry;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-164')]
final class GroupSaleApiTest extends WebTestCase
{
    private const int OWNER_CORPORATION_ID = 98_164_001;
    private const int FOREIGN_CORPORATION_ID = 98_164_002;
    private const int QUANTITY_ABOVE_THE_INTEGER_COLUMN = 2147483648;

    private static int $nextEveCharacterId = 2_112_164_001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $admin;
    private User $member;
    private GroupIndustryProject $sabreDoctrine;
    private GroupIndustryProject $hecateBatch;
    private GroupIndustrySale $sabreSale;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->owner = $this->persistPilot('Aria Solberg', self::OWNER_CORPORATION_ID);
        $this->admin = $this->persistPilot('Brann Holt', self::FOREIGN_CORPORATION_ID);
        $this->member = $this->persistPilot('Dorn Achar', self::FOREIGN_CORPORATION_ID);
        $this->sabreDoctrine = $this->persistProject('Sabre doctrine', GroupProjectStatus::Selling);
        $this->addMembership($this->sabreDoctrine, $this->owner, GroupMemberRole::Owner);
        $this->addMembership($this->sabreDoctrine, $this->admin, GroupMemberRole::Admin);
        $this->addMembership($this->sabreDoctrine, $this->member, GroupMemberRole::Member);
        $this->hecateBatch = $this->persistProject('Hecate batch', GroupProjectStatus::Selling);
        $this->addMembership($this->hecateBatch, $this->owner, GroupMemberRole::Owner);
        $this->sabreSale = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity(2)
            ->setUnitPrice(900000.0)
            ->setTotalPrice(1800000.0)
            ->setVenue('Amarr VIII (Oris)')
            ->setSoldAt(new \DateTimeImmutable('2026-10-21T16:45:00+00:00'))
            ->setRecordedBy($this->admin);
        $this->sabreDoctrine->addSale($this->sabreSale);
        $this->em->flush();
    }

    public function testSaleWithAnInvalidSaleDateIsRejectedOnTheSaleDateAndNothingIsRecorded(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->postIshtarSale(['soldAt' => 'not-a-date']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['soldAt'], 1], [$this->violatedPaths(), $this->salesCountOf($this->sabreDoctrine)]);
    }

    public function testPlainMemberSendingAnInvalidSaleDateIsToldAboutTheDateBeforeTheAccessRefusal(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->postIshtarSale(['soldAt' => 'not-a-date']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSaleWithoutQuantityIsRejectedOnTheQuantity(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->postIshtarSale([], without: 'quantity');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['quantity'], $this->violatedPaths());
    }

    public function testSaleWithAQuantityAboveTheIntegerColumnIsRejectedOnTheQuantity(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->postIshtarSale(['quantity' => self::QUANTITY_ABOVE_THE_INTEGER_COLUMN]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['quantity'], $this->violatedPaths());
    }

    public function testPastedIshtarLineWithEveryValueOutOfRangeCreatesNoProject(): void
    {
        $projectsBefore = $this->em->getRepository(GroupIndustryProject::class)->count([]);
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', '/api/group-industry/projects', [
            'items' => [['typeId' => 0, 'typeName' => 'Ishtar', 'meLevel' => 12, 'teLevel' => 25, 'runs' => 0]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $violatedPaths = $this->violatedPaths();
        sort($violatedPaths);
        self::assertSame(
            [['items[0][meLevel]', 'items[0][runs]', 'items[0][teLevel]'], $projectsBefore],
            [$violatedPaths, $this->em->getRepository(GroupIndustryProject::class)->count([])],
        );
    }

    public function testAdminCorrectsTheUnitPriceOfASale(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('PATCH', $this->sabreSaleUri($this->sabreDoctrine), ['unitPrice' => 950000]);

        self::assertResponseStatusCodeSame(200);
        $sale = $this->responseJson();
        self::assertSame([2, 950000.0, 1900000.0], [$sale['quantity'], (float) $sale['unitPrice'], (float) $sale['totalPrice']]);
        self::assertSame([950000.0, 1900000.0], $this->storedSabreSaleAmounts());
    }

    public function testCorrectionWithAZeroQuantityIsRejectedOnTheQuantityAndKeepsTheSale(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('PATCH', $this->sabreSaleUri($this->sabreDoctrine), ['quantity' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['quantity'], [900000.0, 1800000.0]], [$this->violatedPaths(), $this->storedSabreSaleAmounts()]);
    }

    public function testPlainMemberCorrectingWithAnInvalidQuantityIsRefusedBeforeTheValidation(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('PATCH', $this->sabreSaleUri($this->sabreDoctrine), ['quantity' => 0]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([900000.0, 1800000.0], $this->storedSabreSaleAmounts());
    }

    public function testCorrectionThroughAnotherProjectOfTheOwnerIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->sabreSaleUri($this->hecateBatch), ['unitPrice' => 950000]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([900000.0, 1800000.0], $this->storedSabreSaleAmounts());
    }

    public function testAdminDeletesASale(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('DELETE', $this->sabreSaleUri($this->sabreDoctrine));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->salesCountOf($this->sabreDoctrine));
    }

    public function testPlainMemberCannotDeleteASale(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('DELETE', $this->sabreSaleUri($this->sabreDoctrine));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->salesCountOf($this->sabreDoctrine));
    }

    public function testCorrectionOfASaleWhoseIdIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('PATCH', $this->saleUriWithANonUuidId(), ['unitPrice' => 950000]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([900000.0, 1800000.0], $this->storedSabreSaleAmounts());
    }

    public function testDeletionOfASaleWhoseIdIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('DELETE', $this->saleUriWithANonUuidId());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->salesCountOf($this->sabreDoctrine));
    }

    public function testPastedItemsThatAreNotObjectsCreateNoProject(): void
    {
        $projectsBefore = $this->em->getRepository(GroupIndustryProject::class)->count([]);
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', '/api/group-industry/projects', ['items' => ['x']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['items[0]'], $projectsBefore], [$this->violatedPaths(), $this->em->getRepository(GroupIndustryProject::class)->count([])]);
    }

    public function testItemWithNullRunsCreatesNoProject(): void
    {
        $projectsBefore = $this->em->getRepository(GroupIndustryProject::class)->count([]);
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', '/api/group-industry/projects', [
            'items' => [['typeId' => 12005, 'typeName' => 'Ishtar', 'meLevel' => 10, 'teLevel' => 20, 'runs' => null]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['items[0][runs]'], $projectsBefore], [$this->violatedPaths(), $this->em->getRepository(GroupIndustryProject::class)->count([])]);
    }

    #[Group('issue-241')]
    public function testSaleNamingASellerOfIdentifierZeroIsRejectedOnTheSellerAndNothingIsRecorded(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->postIshtarSale(['sellerCharacterId' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['sellerCharacterId'], 1], [$this->violatedPaths(), $this->salesCountOf($this->sabreDoctrine)]);
    }

    #[Group('issue-241')]
    public function testPlainMemberNamingANegativeSellerIsToldAboutTheSellerBeforeTheAccessRefusal(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->postIshtarSale(['sellerCharacterId' => -5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sellerCharacterId'], $this->violatedPaths());
    }

    #[Group('issue-241')]
    public function testAdminRecordsASaleSoldByHisMainAndReadsItsSellerInTheResponse(): void
    {
        $adminMainEveCharacterId = $this->admin->getMainCharacter()?->getEveCharacterId();
        $this->client->loginUser($this->admin, 'api');
        $this->postIshtarSale(['sellerCharacterId' => $adminMainEveCharacterId]);

        self::assertResponseStatusCodeSame(201);
        $sale = $this->responseJson();
        self::assertSame(
            [$adminMainEveCharacterId, 'Brann Holt', 'Brann Holt', 6000000.0, 2],
            [$sale['sellerCharacterId'], $sale['sellerCharacterName'], $sale['recordedByCharacterName'], (float) $sale['totalPrice'], $this->salesCountOf($this->sabreDoctrine)],
        );
    }

    #[Group('issue-241')]
    public function testOwnerNamingTheMainOfTheAdminAsSellerIsRefusedAndNothingIsRecorded(): void
    {
        $adminMainEveCharacterId = $this->admin->getMainCharacter()?->getEveCharacterId();
        $this->client->loginUser($this->owner, 'api');
        $this->postIshtarSale(['sellerCharacterId' => $adminMainEveCharacterId]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->salesCountOf($this->sabreDoctrine));
    }

    #[Group('issue-241')]
    public function testCorrectionNamingASellerOfIdentifierZeroIsRejectedOnTheSellerAndKeepsTheSale(): void
    {
        $this->client->loginUser($this->admin, 'api');
        $this->request('PATCH', $this->sabreSaleUri($this->sabreDoctrine), ['sellerCharacterId' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['sellerCharacterId'], [900000.0, 1800000.0]], [$this->violatedPaths(), $this->storedSabreSaleAmounts()]);
    }

    #[Group('issue-241')]
    public function testOwnerReadsTheSaleSuggestionsOfTheProjectThroughItsRoute(): void
    {
        $t1 = $this->persistSabreSaleOfTheOwnerMain();
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->saleSuggestionsUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$t1->getId()?->toRfc4122(), 22456, 'Sabre', 3, 183_750_000.0, 'Aria Solberg']],
            array_map(
                static fn (array $suggestion): array => [
                    $suggestion['transactionId'],
                    $suggestion['typeId'],
                    $suggestion['typeName'],
                    $suggestion['quantity'],
                    (float) $suggestion['totalPrice'],
                    $suggestion['sellerCharacterName'],
                ],
                $this->responseJson()['member'],
            ),
        );
    }

    #[Group('issue-241')]
    public function testPlainMemberIsRefusedTheSaleSuggestions(): void
    {
        $this->persistSabreSaleOfTheOwnerMain();
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->saleSuggestionsUri());

        self::assertResponseStatusCodeSame(403);
    }

    #[Group('issue-241')]
    public function testOwnerRecordsASaleFromHisWalletTransactionThroughItsRoute(): void
    {
        $t1 = $this->persistSabreSaleOfTheOwnerMain();
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->saleFromWalletTransactionUri(), ['transactionId' => $t1->getId()?->toRfc4122(), 'quantity' => 2]);

        self::assertResponseStatusCodeSame(201);
        $sale = $this->responseJson();
        self::assertSame(
            [2, 122_500_000.0, 'Aria Solberg', true, 2],
            [$sale['quantity'], (float) $sale['totalPrice'], $sale['sellerCharacterName'], $sale['linkedToWalletTransaction'], $this->salesCountOf($this->sabreDoctrine)],
        );
    }

    #[Group('issue-241')]
    public function testL11UnknownTransactionIsNotFoundAndNothingIsRecorded(): void
    {
        $this->persistSabreSaleOfTheOwnerMain();
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->saleFromWalletTransactionUri(), ['transactionId' => '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Transaction not found', 1], [$this->responseJson()['detail'] ?? null, $this->salesCountOf($this->sabreDoctrine)]);
    }

    #[Group('issue-241')]
    public function testL11TransactionIdentifierThatIsNotAUuidIsRejectedOnTheTransactionIdentifier(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->saleFromWalletTransactionUri(), ['transactionId' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['transactionId'], 1], [$this->violatedPaths(), $this->salesCountOf($this->sabreDoctrine)]);
    }

    #[Group('issue-241')]
    public function testPlainMemberSendingAZeroQuantityIsToldAboutTheQuantityBeforeTheAccessRefusal(): void
    {
        $t1 = $this->persistSabreSaleOfTheOwnerMain();
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->saleFromWalletTransactionUri(), ['transactionId' => $t1->getId()?->toRfc4122(), 'quantity' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['quantity'], $this->violatedPaths());
    }

    #[Group('issue-241')]
    public function testOwnerRecordingTheTransactionOfTheAdminIsNotFound(): void
    {
        $adminSale = $this->persistWalletSale($this->admin, 6_100_000_005, 2, 61_000_000.0);
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->saleFromWalletTransactionUri(), ['transactionId' => $adminSale->getId()?->toRfc4122()]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->salesCountOf($this->sabreDoctrine));
    }

    private function persistSabreSaleOfTheOwnerMain(): CachedWalletTransaction
    {
        return $this->persistWalletSale($this->owner, 6_100_000_001, 3, 61_250_000.0);
    }

    private function persistWalletSale(User $seller, int $transactionId, int $quantity, float $unitPrice): CachedWalletTransaction
    {
        if ($this->sabreDoctrine->getItems()->isEmpty()) {
            $this->sabreDoctrine->addItem((new GroupIndustryProjectItem())->setTypeId(22456)->setTypeName('Sabre')->setMeLevel(10)->setRuns(1));
        }
        $sellerCharacter = $seller->getMainCharacter();
        \assert($sellerCharacter instanceof Character);
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($sellerCharacter)
            ->setTransactionId($transactionId)
            ->setIsBuy(false)
            ->setTypeId(22456)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDate(new \DateTimeImmutable('+1 minute'))
            ->setLocationId(60003760)
            ->setClientId(2_119_000_241);
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    private function saleSuggestionsUri(): string
    {
        return sprintf('/api/group-industry/projects/%s/sale-suggestions', $this->sabreDoctrine->getId()?->toRfc4122());
    }

    private function saleFromWalletTransactionUri(): string
    {
        return sprintf('/api/group-industry/projects/%s/sales/from-wallet-transaction', $this->sabreDoctrine->getId()?->toRfc4122());
    }

    private function persistPilot(string $name, int $corporationId): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function persistProject(string $name, GroupProjectStatus $status): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())->setOwner($this->owner)->setName($name)->setStatus($status);
        $this->em->persist($project);

        return $project;
    }

    private function addMembership(GroupIndustryProject $project, User $user, GroupMemberRole $role): void
    {
        $project->addMember(
            (new GroupIndustryProjectMember())->setUser($user)->setRole($role)->setStatus(GroupMemberStatus::Accepted),
        );
    }

    private function postIshtarSale(array $changes, ?string $without = null): void
    {
        $sale = array_merge(['typeId' => 0, 'typeName' => 'Ishtar', 'quantity' => 5, 'unitPrice' => 1200000], $changes);
        if ($without !== null) {
            unset($sale[$without]);
        }

        $this->request('POST', sprintf('/api/group-industry/projects/%s/sales', $this->sabreDoctrine->getId()?->toRfc4122()), $sale);
    }

    private function sabreSaleUri(GroupIndustryProject $project): string
    {
        return sprintf('/api/group-industry/projects/%s/sales/%s', $project->getId()?->toRfc4122(), $this->sabreSale->getId()?->toRfc4122());
    }

    private function saleUriWithANonUuidId(): string
    {
        return sprintf('/api/group-industry/projects/%s/sales/abc', $this->sabreDoctrine->getId()?->toRfc4122());
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/ld+json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_column($this->responseJson()['violations'] ?? [], 'propertyPath')));
    }

    private function salesCountOf(GroupIndustryProject $project): int
    {
        $this->em->clear();

        return $this->em->getRepository(GroupIndustrySale::class)->count(['project' => $project->getId()]);
    }

    private function storedSabreSaleAmounts(): array
    {
        $this->em->clear();
        $sale = $this->em->find(GroupIndustrySale::class, $this->sabreSale->getId());

        return [$sale?->getUnitPrice(), $sale?->getTotalPrice()];
    }
}
