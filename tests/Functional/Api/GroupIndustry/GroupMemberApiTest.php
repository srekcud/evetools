<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\GroupIndustry;

use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProjectMember;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class GroupMemberApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberListsTheAcceptedAndPendingMembersWithTheirApprovedContributions(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->membersUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['Aria Solberg', self::OWNER_CORPORATION_ID, 'owner', 'accepted', 499_500.0, 1],
                ['Brann Holt', self::MEMBER_CORPORATION_ID, 'member', 'accepted', 55_500.0, 1],
                ['Dorn Achar', self::MEMBER_CORPORATION_ID, 'member', 'pending', 0.0, 0],
            ],
            $this->memberRows(),
        );
    }

    public function testOutsiderIsRefusedTheMemberList(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->membersUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testPendingMemberIsRefusedTheMemberList(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', $this->membersUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testCorpmateOfTheOwnerWhoIsNotAMemberIsRefusedTheMemberList(): void
    {
        $this->client->loginUser($this->ownerCorpmate, 'api');
        $this->request('GET', $this->membersUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testOwnerAcceptsAPendingMember(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->memberUri($this->pendingMembership), ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(200);
        $member = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->pendingMembership), 'Dorn Achar', 'member', 'accepted'],
            [$member['id'], $member['characterName'], $member['role'], $member['status']],
        );
        self::assertSame(['member', 'accepted'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testOwnerPromotesAnAcceptedMemberToAdmin(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->memberUri($this->memberMembership), ['role' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['admin', 'accepted'], [$this->responseJson()['role'], $this->responseJson()['status']]);
        self::assertSame(['admin', 'accepted'], $this->storedRoleAndStatusOf($this->memberMembership));
    }

    public function testOutsiderCannotAcceptAPendingMember(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('PATCH', $this->memberUri($this->pendingMembership), ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['member', 'pending'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testPlainMemberCannotAcceptAPendingMember(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('PATCH', $this->memberUri($this->pendingMembership), ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['member', 'pending'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testOwnerCannotChangeHisOwnOwnerMembership(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->memberUri($this->ownerMembership), ['role' => 'member']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot modify the project owner'], $this->errorDetail());
        self::assertSame(['owner', 'accepted'], $this->storedRoleAndStatusOf($this->ownerMembership));
    }

    public function testUpdateOfAMemberThroughAnotherProjectOfTheOwnerIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->projectUri($this->hecateBatch).'/members/'.$this->idOf($this->pendingMembership), ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Member not found'], $this->errorDetail());
        self::assertSame(['member', 'pending'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testUpdateOfAMemberWhoseIdIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->membersUri().'/'.self::NOT_A_UUID, ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerKicksAMemberWhoKeepsHisContributions(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->memberUri($this->memberMembership));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['member', 'removed'], $this->storedRoleAndStatusOf($this->memberMembership));
        self::assertSame(2, $this->storedContributionCountOf($this->memberMembership));
    }

    public function testOutsiderCannotKickAMember(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('DELETE', $this->memberUri($this->memberMembership));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['member', 'accepted'], $this->storedRoleAndStatusOf($this->memberMembership));
    }

    public function testPlainMemberCannotKickAPendingMember(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('DELETE', $this->memberUri($this->pendingMembership));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['member', 'pending'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testOwnerCannotKickHimself(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->memberUri($this->ownerMembership));

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot kick the project owner'], $this->errorDetail());
        self::assertSame(['owner', 'accepted'], $this->storedRoleAndStatusOf($this->ownerMembership));
    }

    public function testKickOfAMemberThroughAnotherProjectOfTheOwnerIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->projectUri($this->hecateBatch).'/members/'.$this->idOf($this->memberMembership));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Member not found'], $this->errorDetail());
        self::assertSame(['member', 'accepted'], $this->storedRoleAndStatusOf($this->memberMembership));
    }

    public function testKickOfAMemberWhoseIdIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('DELETE', $this->membersUri().'/'.self::NOT_A_UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptedMemberLeavesTheProjectAndKeepsHisContributions(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->leaveUri(), []);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['member', 'removed'], $this->storedRoleAndStatusOf($this->memberMembership));
        self::assertSame(2, $this->storedContributionCountOf($this->memberMembership));
    }

    public function testOutsiderLeavingTheProjectIsToldHeIsNotAMember(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('POST', $this->leaveUri(), []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'You are not a member of this project'], $this->errorDetail());
    }

    public function testPendingMemberLeavingTheProjectIsToldHeIsNotAMemberAndStaysPending(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('POST', $this->leaveUri(), []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'You are not a member of this project'], $this->errorDetail());
        self::assertSame(['member', 'pending'], $this->storedRoleAndStatusOf($this->pendingMembership));
    }

    public function testOwnerCannotLeaveHisProject(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->leaveUri(), []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Owner cannot leave the project'], $this->errorDetail());
        self::assertSame(['owner', 'accepted'], $this->storedRoleAndStatusOf($this->ownerMembership));
    }

    private function membersUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/members';
    }

    private function memberUri(GroupIndustryProjectMember $membership): string
    {
        return $this->membersUri().'/'.$this->idOf($membership);
    }

    private function leaveUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/leave';
    }

    private function memberRows(): array
    {
        return array_map(
            static fn (array $member): array => [
                $member['characterName'],
                $member['corporationId'],
                $member['role'],
                $member['status'],
                (float) $member['totalContributionValue'],
                $member['contributionCount'],
            ],
            $this->rowsSortedBy('characterName', $this->responseJson()),
        );
    }

    private function storedRoleAndStatusOf(GroupIndustryProjectMember $membership): array
    {
        $this->em->clear();
        $stored = $this->em->find(GroupIndustryProjectMember::class, $membership->getId());

        return [$stored?->getRole()->value, $stored?->getStatus()->value];
    }

    private function storedContributionCountOf(GroupIndustryProjectMember $membership): int
    {
        $this->em->clear();

        return $this->em->getRepository(GroupIndustryContribution::class)->count(['member' => $membership->getId()]);
    }
}
