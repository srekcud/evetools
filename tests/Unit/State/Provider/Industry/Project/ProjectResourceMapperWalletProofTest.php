<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectResourceMapper::class)]
#[Group('issue-1092')]
final class ProjectResourceMapperWalletProofTest extends TestCase
{
    private ProjectResourceMapper $mapper;
    private IndustryProject $project;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $this->mapper = new ProjectResourceMapper();
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->line = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1_000)->setIsJob(false);
        $this->project->addBomItem($this->line);
    }

    public function testMaterialContributionExposesItsWalletBackedQuantity(): void
    {
        $this->line->addPurchase($this->walletPurchase(60));
        $this->line->addPurchase($this->manualPurchase(40));

        $resource = $this->mapper->contributionToResource($this->contribution(ContributionType::Material, 100));

        self::assertSame(100, $resource->quantity);
        self::assertSame(60, $resource->walletBackedQuantity);
    }

    public function testNonMaterialContributionExposesNoWalletBackedQuantity(): void
    {
        $resource = $this->mapper->contributionToResource($this->contribution(ContributionType::JobInstall, 10));

        self::assertNull($resource->walletBackedQuantity);
    }

    private function contribution(ContributionType $type, int $quantity): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($this->project->getOwnerParticipant())
            ->setBomItem($this->line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(1_000.0)
            ->setStatus(ContributionStatus::Pending)
            ->setIsAutoDetected(true);
        $this->project->addContribution($contribution);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'id'))->setValue($contribution, Uuid::v4());

        return $contribution;
    }

    private function walletPurchase(int $quantity): IndustryProjectPurchase
    {
        return $this->manualPurchase($quantity)->setSource('esi_wallet')->setTransaction(new CachedWalletTransaction());
    }

    private function manualPurchase(int $quantity): IndustryProjectPurchase
    {
        return (new IndustryProjectPurchase())
            ->setTypeId(34)
            ->setQuantity($quantity)
            ->setUnitPrice(5.0)
            ->setTotalPrice(5.0 * $quantity)
            ->setSource('manual');
    }
}
