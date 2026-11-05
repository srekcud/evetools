<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\IndustryProject;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Tests\Functional\Api\GroupIndustry\BuildsIshtarGroupProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-362')]
final class ProjectLifecycleApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string UNKNOWN_PROJECT = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';
    private const int FRESH_PILOT_CORPORATION_ID = self::OWNER_CORPORATION_ID;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    #[DataProvider('readRoutes')]
    public function testOutsiderIsRefusedAndAnUnknownProjectIsNotFound(string $uriAfterTheProject): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->projectUri($this->ishtarBatch).$uriAfterTheProject);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());

        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects/'.self::UNKNOWN_PROJECT.$uriAfterTheProject);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public static function readRoutes(): iterable
    {
        yield 'detail' => [''];
        yield 'bom' => ['/bom'];
    }

    #[DataProvider('groupIndustryRoutes')]
    public function testGroupIndustryRouteMatchesNoRoute(string $method, string $uri, ?array $body): void
    {
        $this->actAs($this->owner);
        $this->request($method, str_replace('{project}', $this->ishtarBatch->getId()?->toRfc4122(), $uri), $body);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->client->getRequest()->attributes->get('_route'));
    }

    public static function groupIndustryRoutes(): iterable
    {
        yield 'list' => ['GET', '/api/group-industry/projects', null];
        yield 'creation' => ['POST', '/api/group-industry/projects', []];
        yield 'discovery' => ['GET', '/api/group-industry/projects/available', null];
        yield 'detail' => ['GET', '/api/group-industry/projects/{project}', null];
        yield 'bom' => ['GET', '/api/group-industry/projects/{project}/bom', null];
        yield 'join' => ['POST', '/api/group-industry/projects/join/abcdef0123', []];
        yield 'line rental rates' => ['GET', '/api/group-industry/line-rental-rates', null];
    }

    #[DataProvider('statusChangesOfADraftProject')]
    public function testOwnerChangesTheStatusOfADraftProject(string $target, int $expectedStatusCode, string $expectedStatusOrDetail): void
    {
        $this->hecateBatch->setStatus(GroupProjectStatus::Draft);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['status' => $target]);

        self::assertResponseStatusCodeSame($expectedStatusCode);
        $body = $this->responseJson();
        self::assertSame($expectedStatusOrDetail, $expectedStatusCode === 200 ? $body['status'] : $body['detail']);
    }

    public static function statusChangesOfADraftProject(): iterable
    {
        yield 'draft to published' => ['published', 200, 'published'];
        yield 'draft to in progress' => ['in_progress', 200, 'in_progress'];
        yield 'draft to selling' => ['selling', 400, 'Cannot transition from "draft" to "selling"'];
    }

    public function testSameCorporationJoinsByCodeAcceptedAndOtherCorporationPendingOnceTheOwnerOpensTheProject(): void
    {
        $this->ishtarBatch->setStatus(GroupProjectStatus::Published);
        $this->em->flush();
        $code = $this->ishtarBatch->getShortLinkCode();

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['private' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseJson()['private']);

        $this->actAs($this->ownerCorpmate);
        $this->request('POST', '/api/industry/projects/join/'.$code, []);
        self::assertResponseIsSuccessful();
        self::assertSame(['member', 'accepted'], [$this->responseJson()['myRole'], $this->responseJson()['myStatus']]);

        $this->actAs($this->outsider);
        $this->request('POST', '/api/industry/projects/join/'.$code, []);
        self::assertResponseIsSuccessful();
        self::assertSame(['member', 'pending'], [$this->responseJson()['myRole'], $this->responseJson()['myStatus']]);
    }

    public function testJoiningAPrivateProjectByItsCodeIsNotFound(): void
    {
        $this->ishtarBatch->setStatus(GroupProjectStatus::Published);
        $this->em->flush();

        $this->actAs($this->ownerCorpmate);
        $this->request('POST', '/api/industry/projects/join/'.$this->ishtarBatch->getShortLinkCode(), []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testOwnerClosingTheProjectAgainKeepsItsParticipantsButHidesItFromDiscoveryAndFromTheCode(): void
    {
        $this->ishtarBatch->setStatus(GroupProjectStatus::Published)->setPrivate(false);
        $this->em->flush();
        $code = $this->ishtarBatch->getShortLinkCode();
        $newcomer = $this->persistPilot('Fenn Orr', self::FRESH_PILOT_CORPORATION_ID);
        $this->em->flush();

        $this->actAs($this->ownerCorpmate);
        $this->request('POST', '/api/industry/projects/join/'.$code, []);
        self::assertResponseIsSuccessful();

        $this->actAs($newcomer);
        $this->request('GET', '/api/industry/projects/available');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Ishtar batch'], array_column($this->responseJson(), 'name'));

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['private' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseJson()['private']);

        $this->actAs($this->ownerCorpmate);
        $this->request('GET', $this->projectUri($this->ishtarBatch));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['member', 'accepted'], [$this->responseJson()['myRole'], $this->responseJson()['myStatus']]);

        $this->actAs($newcomer);
        $this->request('GET', '/api/industry/projects/available');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());

        $this->request('POST', '/api/industry/projects/join/'.$code, []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testOwnerCannotDeleteAProjectWithAnApprovedContributionOrASale(): void
    {
        $this->actAs($this->owner);
        $this->request('DELETE', $this->projectUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            [400, 'A completed project, or one with an approved contribution or a sale, cannot be deleted'],
            $this->errorDetail(),
        );
        self::assertSame(1, $this->em->getRepository(IndustryProject::class)->count(['name' => 'Ishtar batch']));
    }

    public function testOwnerDeletesADraftProjectWithoutContributionNorSale(): void
    {
        $draft = $this->persistProject('Empty draft')->setStatus(GroupProjectStatus::Draft);
        $this->addMembership($draft, $this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->em->flush();
        $draftUri = $this->projectUri($draft);

        $this->actAs($this->owner);
        $this->request('DELETE', $draftUri);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->em->getRepository(IndustryProject::class)->count(['name' => 'Empty draft']));
    }
}
