<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1159')]
final class SoloContributionCorrectionApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int SUPPLIED_QUANTITY = 1_000;
    private const float SUPPLIED_VALUE = 4_400.0;

    private IndustryProjectContribution $suppliedTritanium;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->hecateTritaniumLine->setFulfilledQuantity(self::SUPPLIED_QUANTITY);
        $this->suppliedTritanium = $this->addContribution($this->hecateOwnerMembership, $this->hecateTritaniumLine, ContributionType::Material, self::SUPPLIED_QUANTITY, self::SUPPLIED_VALUE, ContributionStatus::Approved);
        $this->em->flush();
    }

    public function testTheOwnerOfASoloProjectCorrectsTheNoteOfAnApprovedContributionAndItStaysApproved(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->suppliedUri().'/details', ['note' => 'recounted']);

        self::assertResponseStatusCodeSame(200);
        $contribution = $this->responseJson();
        self::assertSame(['approved', 'recounted', self::SUPPLIED_QUANTITY], [$contribution['status'], $contribution['note'], $contribution['quantity']]);
        self::assertSame(self::SUPPLIED_QUANTITY, $this->storedLine()->getFulfilledQuantity());
    }

    public function testTheOwnerOfASoloProjectWithdrawsAnApprovedContributionAndTheLineIsGivenTheQuantityBack(): void
    {
        $this->actAs($this->owner);
        $this->request('DELETE', $this->suppliedUri());

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->storedLine()->getFulfilledQuantity());
        self::assertNull($this->em->find(IndustryProjectContribution::class, $this->suppliedTritanium->getId()));
    }

    public function testTheListFlagsTheApprovedContributionOfASoloProjectAsEditable(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch).'/contributions');

        self::assertResponseStatusCodeSame(200);
        $editableIds = array_column(array_filter($this->responseJson(), static fn (array $row): bool => $row['editableByViewer']), 'id');
        self::assertSame([$this->idOf($this->suppliedTritanium)], $editableIds);
    }

    public function testAnApprovedContributionOfAProjectWithATeamStaysLockedForItsContributor(): void
    {
        $this->actAs($this->member);
        $this->request('DELETE', $this->projectUri($this->ishtarBatch).'/contributions/'.$this->idOf($this->memberMorphiteContribution));

        self::assertResponseStatusCodeSame(400);
    }

    private function suppliedUri(): string
    {
        return $this->projectUri($this->hecateBatch).'/contributions/'.$this->idOf($this->suppliedTritanium);
    }

    private function storedLine(): IndustryProjectBomLine
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectBomLine::class, $this->hecateTritaniumLine->getId());
    }
}
