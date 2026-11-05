<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\State\Processor\Me\DeleteCharacterProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-241')]
final class SaleSellerCharacterRemovalTest extends IntegrationTestCase
{
    private const int O_MAIN_EVE_CHARACTER_ID = 2120000001;
    private const int O_ALT_EVE_CHARACTER_ID = 2120000002;

    private string $ownerId;
    private string $oAltId;
    private string $projectId;
    private string $saleSoldByTheAltId;
    private string $saleSoldByTheMainId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->createUser();
        $oMain = $this->createCharacter($owner, 'O-Main')->setEveCharacterId(self::O_MAIN_EVE_CHARACTER_ID);
        $oAlt = $this->createCharacter($owner, 'O-Alt')->setEveCharacterId(self::O_ALT_EVE_CHARACTER_ID);
        $owner->setMainCharacter($oMain);
        $project = (new IndustryProject())->setProductTypeId(587)->setRuns(1)->setOwner($owner)->setName('Sabre')->setStatus(GroupProjectStatus::Selling);
        $this->em->persist($project);
        $project->addMember((new IndustryProjectParticipant())->setUser($owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted));
        $saleSoldByTheAlt = $this->addSale($project, $owner, 3, 61_250_000.0, '2026-10-24T21:05:00+00:00')->setSeller($oAlt);
        $saleSoldByTheMain = $this->addSale($project, $owner, 2, 60_000_000.0, '2026-10-23T19:10:00+00:00')->setSeller($oMain);
        $this->em->flush();

        $this->ownerId = (string) $owner->getId();
        $this->oAltId = (string) $oAlt->getId();
        $this->projectId = (string) $project->getId();
        $this->saleSoldByTheAltId = (string) $saleSoldByTheAlt->getId();
        $this->saleSoldByTheMainId = (string) $saleSoldByTheMain->getId();
        $this->em->clear();
    }

    public function testA11SaleSoldByARemovedCharacterIsKeptWithAnUnknownSellerAndItsAmounts(): void
    {
        $this->removeTheAltOfTheOwner();

        $sale = $this->sale($this->saleSoldByTheAltId);
        self::assertSame(
            [null, 3, 61_250_000.0, 183_750_000.0],
            [$sale->getSeller(), $sale->getQuantity(), $sale->getUnitPrice(), $sale->getTotalPrice()],
        );
    }

    public function testSaleSoldByAnotherCharacterOfTheSameAccountKeepsItsSeller(): void
    {
        $this->removeTheAltOfTheOwner();

        self::assertSame(self::O_MAIN_EVE_CHARACTER_ID, $this->sale($this->saleSoldByTheMainId)->getSeller()?->getEveCharacterId());
    }

    public function testRemovalOfTheSellerLeavesTheRevenueOfTheDistributionUnchanged(): void
    {
        $revenueBefore = $this->totalRevenue();

        $this->removeTheAltOfTheOwner();

        self::assertSame([303_750_000.0, 303_750_000.0], [$revenueBefore, $this->totalRevenue()]);
    }

    public function testSalesListShowsTheSaleOfTheRemovedCharacterWithAnUnknownSeller(): void
    {
        $this->removeTheAltOfTheOwner();

        self::assertSame(
            [
                [null, null, 'O-Main', 183_750_000.0],
                [self::O_MAIN_EVE_CHARACTER_ID, 'O-Main', 'O-Main', 120_000_000.0],
            ],
            array_map(
                static fn (GroupIndustrySaleResource $sale): array => [
                    $sale->sellerCharacterId,
                    $sale->sellerCharacterName,
                    $sale->recordedByCharacterName,
                    $sale->totalPrice,
                ],
                $this->listedSales(),
            ),
        );
    }

    private function removeTheAltOfTheOwner(): void
    {
        $processor = new DeleteCharacterProcessor(
            $this->securityOfTheOwner(),
            self::getContainer()->get(CharacterRepository::class),
            $this->em,
        );
        $processor->process(null, new Delete(), ['id' => $this->oAltId]);
        $this->em->clear();

        self::assertNull($this->em->find(Character::class, $this->oAltId));
    }

    private function listedSales(): array
    {
        $provider = new GroupSaleCollectionProvider(
            $this->securityOfTheOwner(),
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(IndustryProjectSaleRepository::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $this->projectId]);
    }

    private function totalRevenue(): float
    {
        $project = $this->em->find(IndustryProject::class, $this->projectId);
        \assert($project instanceof IndustryProject);
        $distributionService = self::getContainer()->get(GroupIndustryDistributionService::class);
        \assert($distributionService instanceof GroupIndustryDistributionService);

        return $distributionService->calculateDistribution($project)->totalRevenue;
    }

    private function securityOfTheOwner(): Security
    {
        $owner = $this->em->find(User::class, $this->ownerId);
        \assert($owner instanceof User);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);

        return $security;
    }

    private function sale(string $saleId): IndustryProjectSale
    {
        $sale = $this->em->find(IndustryProjectSale::class, $saleId);
        \assert($sale instanceof IndustryProjectSale);

        return $sale;
    }

    private function addSale(IndustryProject $project, User $recordedBy, int $quantity, float $unitPrice, string $soldAt): IndustryProjectSale
    {
        $sale = (new IndustryProjectSale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->recalculateTotalPrice()
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable($soldAt))
            ->setRecordedBy($recordedBy);
        $project->addSale($sale);

        return $sale;
    }
}
