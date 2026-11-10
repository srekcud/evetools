<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-343')]
final class ProjectBuyOrBuildSwitchApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const string WORKED_LINE_REFUSAL = 'The line "R.A.M.- Starship Tech" carries a linked job, a purchase or a contribution and cannot be removed from the plan.';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(self::RAM_STARSHIP_TECH_TYPE_ID, self::TRITANIUM_TYPE_ID);
        $this->em->remove($this->memberMorphiteContribution);
        $this->em->flush();
    }

    public function testOwnerSwitchesAComponentToBuyOnAProjectInProgressAndItsLineLeavesThePlan(): void
    {
        $this->em->remove($this->memberRamJobInstall);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['blacklistTypeIds' => [self::RAM_STARSHIP_TECH_TYPE_ID]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::RAM_STARSHIP_TECH_TYPE_ID], $this->responseJson()['blacklistTypeIds']);
        $this->em->refresh($this->ishtarBatch);
        self::assertSame([self::RAM_STARSHIP_TECH_TYPE_ID], $this->ishtarBatch->getBlacklistTypeIds());
        $jobTypeIds = [];
        foreach ($this->ishtarBatch->getBomItems() as $line) {
            if ($line->isJob()) {
                $jobTypeIds[] = $line->getTypeId();
            }
        }
        self::assertSame([self::ISHTAR_TYPE_ID], $jobTypeIds);
    }

    public function testSwitchIsRefusedWhenTheLineToRemoveCarriesAContributionOrALinkedJobAndTheBlacklistIsUnchanged(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['blacklistTypeIds' => [self::RAM_STARSHIP_TECH_TYPE_ID]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, self::WORKED_LINE_REFUSAL], $this->errorDetail());
        $this->em->refresh($this->ishtarBatch);
        self::assertSame([], $this->ishtarBatch->getBlacklistTypeIds());

        $this->em->remove($this->memberRamJobInstall);
        $this->ramJobLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(343_001)->setRuns(self::RAM_RUNS)->setStatus('active'));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['blacklistTypeIds' => [self::RAM_STARSHIP_TECH_TYPE_ID]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, self::WORKED_LINE_REFUSAL], $this->errorDetail());
        $reloadedProject = $this->em->find(IndustryProject::class, $this->ishtarBatch->getId());
        self::assertSame([], $reloadedProject?->getBlacklistTypeIds());
    }

    public function testParticipantWhoIsNotAnAdminCannotSwitchAComponentAndTheBlacklistIsUnchanged(): void
    {
        $this->actAs($this->member);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['blacklistTypeIds' => [self::RAM_STARSHIP_TECH_TYPE_ID]]);

        self::assertResponseStatusCodeSame(403);
        $this->em->refresh($this->ishtarBatch);
        self::assertSame([], $this->ishtarBatch->getBlacklistTypeIds());
    }
}
