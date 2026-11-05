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
final class SoloProjectAccessApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string UNKNOWN_PROJECT = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';
    private const string ADMINISTRATION_REFUSAL = 'Only project owner or admin can perform this action';
    private const string READ_REFUSAL = 'You do not have access to this project';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    #[DataProvider('soloProjectRoutes')]
    public function testOwnerOfASoloProjectReachesEveryRoute(string $method, string $uriAfterTheProject, ?array $body, string $refusal): void
    {
        $this->actAs($this->owner);
        $this->request($method, $this->projectUri($this->hecateBatch).$uriAfterTheProject, $body);

        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('soloProjectRoutes')]
    public function testCorpmateOfTheOwnerIsRefusedEveryRouteOfASoloProject(string $method, string $uriAfterTheProject, ?array $body, string $refusal): void
    {
        $this->actAs($this->ownerCorpmate);
        $this->request($method, $this->projectUri($this->hecateBatch).$uriAfterTheProject, $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, $refusal], $this->errorDetail());
    }

    #[DataProvider('soloProjectRoutes')]
    public function testOutsiderIsRefusedEveryRouteOfASoloProject(string $method, string $uriAfterTheProject, ?array $body, string $refusal): void
    {
        $this->actAs($this->outsider);
        $this->request($method, $this->projectUri($this->hecateBatch).$uriAfterTheProject, $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, $refusal], $this->errorDetail());
    }

    #[DataProvider('soloProjectRoutes')]
    public function testUnknownProjectIsNotFoundOnEveryRoute(string $method, string $uriAfterTheProject, ?array $body, string $refusal): void
    {
        $this->actAs($this->owner);
        $this->request($method, '/api/industry/projects/'.self::UNKNOWN_PROJECT.$uriAfterTheProject, $body);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public static function soloProjectRoutes(): iterable
    {
        yield 'detail' => ['GET', '', null, self::READ_REFUSAL];
        yield 'members' => ['GET', '/members', null, self::READ_REFUSAL];
        yield 'container verification' => ['GET', '/container-verification', null, self::ADMINISTRATION_REFUSAL];
        yield 'update' => ['PATCH', '', ['notes' => 'Bring the fuel blocks'], self::READ_REFUSAL];
    }

    public function testOwnerReadsTheNotesHeSavedOnASoloProject(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->hecateBatch), ['notes' => 'Bring the fuel blocks']);
        self::assertResponseStatusCodeSame(200);

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Bring the fuel blocks', $this->responseJson()['notes']);
    }

    public function testOwnerDeletesADeletableSoloProjectAndOthersAreRefused(): void
    {
        $draft = $this->persistProject('Solo draft')->setStatus(GroupProjectStatus::Draft);
        $this->addMembership($draft, $this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->em->flush();
        $draftUri = $this->projectUri($draft);

        $this->actAs($this->ownerCorpmate);
        $this->request('DELETE', $draftUri);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can delete this project'], $this->errorDetail());

        $this->actAs($this->outsider);
        $this->request('DELETE', $draftUri);
        self::assertResponseStatusCodeSame(403);

        $this->actAs($this->owner);
        $this->request('DELETE', $draftUri);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->em->getRepository(IndustryProject::class)->count(['name' => 'Solo draft']));
    }

    public function testOwnerListsHisSoloProjectInHisProjects(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', '/api/industry/projects');

        self::assertResponseStatusCodeSame(200);
        self::assertEqualsCanonicalizing(
            [['Hecate batch', 'owner', 'accepted'], ['Ishtar batch', 'owner', 'accepted']],
            array_map(static fn (array $project): array => [$project['name'], $project['myRole'], $project['myStatus']], $this->responseJson()),
        );
    }
}
