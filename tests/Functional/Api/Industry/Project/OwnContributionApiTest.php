<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectContribution;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1012')]
final class OwnContributionApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testTheContributorEditsTheValueAndTheNoteOfTheirPendingContribution(): void
    {
        $this->actAs($this->member);
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall).'/details', ['estimatedValue' => 200_000, 'note' => 'Four runs']);

        self::assertResponseStatusCodeSame(200);
        $contribution = $this->responseJson();
        self::assertSame([200_000.0, 'Four runs', 'pending'], [$contribution['estimatedValue'], $contribution['note'], $contribution['status']]);
        $stored = $this->storedContribution($this->memberRamJobInstall);
        self::assertSame([200_000.0, 'Four runs'], [$stored->getEstimatedValue(), $stored->getNote()]);
    }

    public function testTheContributorWithdrawsTheirPendingContributionAndCanSubmitItAgain(): void
    {
        $this->actAs($this->member);
        $this->request('DELETE', $this->contributionUri($this->memberRamJobInstall));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->storedContribution($this->memberRamJobInstall));

        $this->actAs($this->member);
        $this->request('POST', $this->contributionsUri(), ['type' => 'job_install', 'bomItemId' => $this->idOf($this->ramJobLine), 'quantity' => 300, 'estimatedValue' => 150_000]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testTheListFlagsOnlyThePendingContributionsOfTheViewerAsEditable(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(200);
        $editableIds = array_column(array_filter($this->responseJson(), static fn (array $row): bool => $row['editableByViewer']), 'id');
        self::assertSame([$this->idOf($this->memberRamJobInstall)], $editableIds);

        $this->actAs($this->owner);
        $this->request('GET', $this->contributionsUri());

        self::assertSame([], array_column(array_filter($this->responseJson(), static fn (array $row): bool => $row['editableByViewer']), 'id'));
    }

    public function testTheOwnerCannotEditOrWithdrawAParticipantsContribution(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall).'/details', ['note' => 'mine now']);
        self::assertResponseStatusCodeSame(403);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->contributionUri($this->memberRamJobInstall));
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->storedContribution($this->memberRamJobInstall));
    }

    public function testAnApprovedContributionIsLockedForItsContributor(): void
    {
        $this->actAs($this->member);
        $this->request('DELETE', $this->contributionUri($this->memberMorphiteContribution));
        self::assertResponseStatusCodeSame(400);

        $this->actAs($this->member);
        $this->request('PATCH', $this->contributionUri($this->memberMorphiteContribution).'/details', ['quantity' => 2]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(ContributionStatus::Approved, $this->storedContribution($this->memberMorphiteContribution)?->getStatus());
    }

    public function testAnOutsiderAndAPendingMemberAreRefused(): void
    {
        foreach ([$this->outsider, $this->pendingMember] as $user) {
            $this->actAs($user);
            $this->request('DELETE', $this->contributionUri($this->memberRamJobInstall));
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testTheQuantityCannotExceedTheRemainingQuantityOfTheLine(): void
    {
        $this->actAs($this->member);
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall).'/details', ['quantity' => 10_000_000]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(self::RAM_RUNS, $this->storedContribution($this->memberRamJobInstall)?->getQuantity());
    }

    private function contributionsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/contributions';
    }

    private function contributionUri(IndustryProjectContribution $contribution): string
    {
        return $this->contributionsUri().'/'.$this->idOf($contribution);
    }

    private function storedContribution(IndustryProjectContribution $contribution): ?IndustryProjectContribution
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectContribution::class, $contribution->getId());
    }
}
