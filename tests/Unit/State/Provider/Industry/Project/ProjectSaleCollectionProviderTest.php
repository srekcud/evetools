<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\Entity\Character;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSaleCollectionProvider;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectSaleCollectionProvider::class)]
class ProjectSaleCollectionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const int SABRE_TYPE_ID = 22456;
    private const int CAPACITOR_BOOSTER_TYPE_ID = 3554;
    private const int NANITE_PASTE_TYPE_ID = 28668;

    private Security&Stub $security;

    private array $listedSales = [];

    private ProjectSaleCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $saleRepository = $this->createStub(IndustryProjectSaleRepository::class);
        $saleRepository->method('findByProjectForListing')->willReturnCallback(fn (): array => $this->listedSales);

        $this->provider = new ProjectSaleCollectionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $saleRepository,
            $this->createStub(CachedWalletJournalEntryRepository::class),
        );
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testSalesAreReadByTheOwnerAndAcceptedMembersOnly(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->salesOf($this->sabreBatch->getId())));
    }

    public function testSalesOfAnUnknownProjectAreNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->outcomeOf(fn () => $this->salesOf(Uuid::v4())));
    }

    public function testSalesWithoutUserAreUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->salesOf($this->sabreBatch->getId())));
    }

    public function testSalesAreListedInTheListingOrderWithTheNameOfTheirRecorder(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));
        $this->listedSales = [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', $this->pilot('Vexa Mordu')),
            $this->sale(self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, '2026-10-22T08:40:00+00:00', $this->userOfCorporation(self::FOREIGN_CORPORATION_ID)),
            $this->sale(self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, '2026-10-19T16:12:00+00:00', null),
        ];

        $resources = $this->salesOf($this->sabreBatch->getId());

        self::assertSame(
            [
                [self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, 183_750_000.0, 'Jita IV - Moon 4', '2026-10-24T21:05:00+00:00', 'Vexa Mordu'],
                [self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, 25_725_000.0, 'Jita IV - Moon 4', '2026-10-22T08:40:00+00:00', 'Unknown'],
                [self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, 6_097_000.0, 'Jita IV - Moon 4', '2026-10-19T16:12:00+00:00', null],
            ],
            array_map(
                static fn (ProjectSaleResource $resource): array => [
                    $resource->typeId,
                    $resource->typeName,
                    $resource->quantity,
                    $resource->unitPrice,
                    $resource->totalPrice,
                    $resource->venue,
                    $resource->soldAt,
                    $resource->recordedByCharacterName,
                ],
                $resources,
            ),
        );
    }

    #[Group('issue-241')]
    #[Group('issue-339')]
    public function testSalesSoldByTheAltOfAnotherPlayerAreListedWithTheirMainAsSeller(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));
        [$owner, $ownerMain, $ownerAlt] = $this->playerWithAlt();
        $aMain = $this->seller(2120000011, 'A-Main');
        $aPlayer = (new User())->addCharacter($aMain)->setMainCharacter($aMain);
        $this->listedSales = [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', $owner)->setSeller($ownerMain),
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 2, 60_000_000.0, '2026-10-23T19:10:00+00:00', $owner)->setSeller($ownerAlt),
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 1, 62_000_000.0, '2026-10-22T11:20:00+00:00', $aPlayer)->setSeller($aMain),
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 1, 59_500_000.0, '2026-10-21T08:00:00+00:00', $owner),
        ];

        $resources = $this->salesOf($this->sabreBatch->getId());

        self::assertSame(
            [
                [2120000001, 'O-Main', 'O-Main', 183_750_000.0],
                [2120000001, 'O-Main', 'O-Main', 120_000_000.0],
                [2120000011, 'A-Main', 'A-Main', 62_000_000.0],
                [null, null, 'O-Main', 59_500_000.0],
            ],
            $this->sellersOf($resources),
        );
    }

    #[Group('issue-339')]
    public function testSalesSoldByTheAltOfThePlayerReadingThemKeepTheirAltAsSeller(): void
    {
        $player = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $main = $this->seller(2120000001, 'O-Main');
        $alt = $this->seller(2120000002, 'O-Alt');
        $player->method('getCharacters')->willReturn(new ArrayCollection([$main, $alt]));
        $player->method('getMainCharacter')->willReturn($main);
        $this->security->method('getUser')->willReturn($player);
        $this->listedSales = [
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', $player)->setSeller($alt),
            $this->sale(self::SABRE_TYPE_ID, 'Sabre', 2, 60_000_000.0, '2026-10-23T19:10:00+00:00', $player)->setSeller($main),
        ];

        $resources = $this->salesOf($this->sabreBatch->getId());

        self::assertSame(
            [[2120000002, 'O-Alt', 'O-Main', 183_750_000.0], [2120000001, 'O-Main', 'O-Main', 120_000_000.0]],
            $this->sellersOf($resources),
        );
    }

    /**
     * @return array{User, Character, Character}
     */
    private function playerWithAlt(): array
    {
        $main = $this->seller(2120000001, 'O-Main');
        $alt = $this->seller(2120000002, 'O-Alt');
        $player = (new User())->addCharacter($main)->addCharacter($alt)->setMainCharacter($main);

        return [$player, $main, $alt];
    }

    /**
     * @param ProjectSaleResource[] $resources
     */
    private function sellersOf(array $resources): array
    {
        return array_map(
            static fn (ProjectSaleResource $resource): array => [
                $resource->sellerCharacterId,
                $resource->sellerCharacterName,
                $resource->recordedByCharacterName,
                $resource->totalPrice,
            ],
            $resources,
        );
    }

    private function seller(int $eveCharacterId, string $name): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function salesOf(Uuid $projectId): array
    {
        return $this->provider->provide(new GetCollection(), ['projectId' => $projectId->toRfc4122()]);
    }

    private function sale(int $typeId, string $typeName, int $quantity, float $unitPrice, string $soldAt, ?User $recordedBy): IndustryProjectSale
    {
        $sale = new IndustryProjectSale();
        $sale->setProject($this->sabreBatch);
        $sale->setTypeId($typeId);
        $sale->setTypeName($typeName);
        $sale->setQuantity($quantity);
        $sale->setUnitPrice($unitPrice);
        $sale->setTotalPrice($quantity * $unitPrice);
        $sale->setVenue('Jita IV - Moon 4');
        $sale->setSoldAt(new \DateTimeImmutable($soldAt));
        if ($recordedBy !== null) {
            $sale->setRecordedBy($recordedBy);
        }
        (new \ReflectionProperty(IndustryProjectSale::class, 'id'))->setValue($sale, Uuid::v4());

        return $sale;
    }

    private function pilot(string $name): User
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($name);
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }
}
