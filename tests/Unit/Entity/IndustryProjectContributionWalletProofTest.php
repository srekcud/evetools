<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectContribution::class)]
#[Group('issue-1092')]
final class IndustryProjectContributionWalletProofTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;

    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $memberParticipant;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->memberParticipant = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->memberParticipant);
        $this->line = (new IndustryProjectBomLine())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($this->line);
    }

    public function testDetectedContributionFullyBackedByWalletTransactions(): void
    {
        $derived = $this->contribution($this->memberParticipant, ContributionType::Material, 100, true);
        $this->purchase(100, true, $this->memberParticipant);

        self::assertSame(100, $derived->walletBackedQuantity());
    }

    public function testDetectedContributionMixingWalletAndManualPurchasesIsPartlyBacked(): void
    {
        $derived = $this->contribution($this->memberParticipant, ContributionType::Material, 100, true);
        $this->purchase(60, true, $this->memberParticipant);
        $this->purchase(40, false, $this->memberParticipant);

        self::assertSame(60, $derived->walletBackedQuantity());
    }

    public function testDetectedContributionMadeOfManualPurchasesHasNoWalletProof(): void
    {
        $derived = $this->contribution($this->ownerParticipant, ContributionType::Material, 100, true);
        $this->purchase(100, false, null);

        self::assertSame(0, $derived->walletBackedQuantity());
    }

    public function testPurchaseWithoutBuyerIsCountedForTheOwnerOnly(): void
    {
        $ownerDerived = $this->contribution($this->ownerParticipant, ContributionType::Material, 100, true);
        $memberDerived = $this->contribution($this->memberParticipant, ContributionType::Material, 100, true);
        $this->purchase(100, true, null);

        self::assertSame(100, $ownerDerived->walletBackedQuantity());
        self::assertSame(0, $memberDerived->walletBackedQuantity());
    }

    public function testDeclaredContributionWithoutLinkedPurchaseHasNoWalletProof(): void
    {
        $declared = $this->contribution($this->memberParticipant, ContributionType::Material, 100, false);
        $this->purchase(100, true, $this->memberParticipant);

        self::assertSame(0, $declared->walletBackedQuantity());
    }

    public function testDeclaredContributionCountsOnlyTheWalletPurchasesLinkedToIt(): void
    {
        $declared = $this->contribution($this->memberParticipant, ContributionType::Material, 100, false);
        $other = $this->contribution($this->memberParticipant, ContributionType::Material, 100, false);
        $this->purchase(70, true, $this->memberParticipant, $declared);
        $this->purchase(30, false, $this->memberParticipant, $declared);
        $this->purchase(100, true, $this->memberParticipant, $other);

        self::assertSame(70, $declared->walletBackedQuantity());
    }

    public function testWalletBackedQuantityIsCappedAtTheContributionQuantity(): void
    {
        $derived = $this->contribution($this->memberParticipant, ContributionType::Material, 50, true);
        $this->purchase(80, true, $this->memberParticipant);

        self::assertSame(50, $derived->walletBackedQuantity());
    }

    public function testPurchaseOfAnotherTypeIsIgnored(): void
    {
        $derived = $this->contribution($this->memberParticipant, ContributionType::Material, 100, true);
        $this->purchase(100, true, $this->memberParticipant)->setTypeId(self::PYERITE_TYPE_ID);

        self::assertSame(0, $derived->walletBackedQuantity());
    }

    public function testJobInstallContributionHasNoWalletProofFigure(): void
    {
        $jobInstall = $this->contribution($this->memberParticipant, ContributionType::JobInstall, 10, true);
        $this->purchase(10, true, $this->memberParticipant);

        self::assertNull($jobInstall->walletBackedQuantity());
    }

    public function testMaterialContributionWithoutBomLineHasNoWalletProofFigure(): void
    {
        $contribution = $this->contribution($this->memberParticipant, ContributionType::Material, 10, false)->setBomItem(null);

        self::assertNull($contribution->walletBackedQuantity());
    }

    private function contribution(IndustryProjectParticipant $member, ContributionType $type, int $quantity, bool $detected): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($member)
            ->setBomItem($this->line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(1_000.0)
            ->setStatus(ContributionStatus::Pending)
            ->setIsAutoDetected($detected);
        $this->project->addContribution($contribution);

        return $contribution;
    }

    private function purchase(int $quantity, bool $walletBacked, ?IndustryProjectParticipant $buyer, ?IndustryProjectContribution $contribution = null): IndustryProjectPurchase
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice(5.0)
            ->setTotalPrice(5.0 * $quantity)
            ->setSource($walletBacked ? 'esi_wallet' : 'manual')
            ->setTransaction($walletBacked ? new CachedWalletTransaction() : null)
            ->setBuyer($buyer)
            ->setContribution($contribution);
        $this->line->addPurchase($purchase);

        return $purchase;
    }
}
