<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\InventedProductsReader;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectT2BlueprintsToProvideProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectT2BlueprintsToProvideProvider::class)]
#[Group('issue-806')]
final class ProjectT2BlueprintsToProvideProviderTest extends TestCase
{
    private const int ISHTAR = 12_005;
    private const int HAIL_L = 12_779;

    private User $owner;
    private User $member;
    private User $outsider;
    private IndustryProject $project;
    private ?User $actor;

    protected function setUp(): void
    {
        $this->owner = $this->pilot('Aria Solberg', 2_120_806_001);
        $this->member = $this->pilot('Brann Holt', 2_120_806_011);
        $this->outsider = $this->pilot('Elia Varr', 2_120_806_021);
        $this->project = (new IndustryProject())->setOwner($this->owner)->setName('Ishtar and Hail batch');
        $this->actor = $this->owner;
    }

    public function testProjectKeepingTheInventionHasNoBlueprintCopiesToProvide(): void
    {
        $resource = $this->provide();

        self::assertSame([$this->project->getId()->toRfc4122(), false, []], [$resource->id, $resource->excludesInvention, $resource->lines]);
    }

    public function testProjectExcludingTheInventionListsEachInventedProductWithItsManufacturingRunsSortedByTypeName(): void
    {
        $this->project->setExcludesInvention(true);

        $resource = $this->provide();

        self::assertTrue($resource->excludesInvention);
        self::assertSame(
            [
                ['productTypeId' => self::HAIL_L, 'typeName' => 'Hail L', 'runs' => 9],
                ['productTypeId' => self::ISHTAR, 'typeName' => 'Ishtar', 'runs' => 15],
            ],
            $resource->lines,
        );
    }

    public function testAcceptedMemberWhoIsNotTheOwnerReadsTheBlueprintCopiesToProvide(): void
    {
        $this->project->setExcludesInvention(true);
        $this->actor = $this->member;

        self::assertCount(2, $this->provide()->lines);
    }

    public function testUserWhoIsNotAParticipantIsRefused(): void
    {
        $this->project->setExcludesInvention(true);
        $this->actor = $this->outsider;

        $this->expectException(AccessDeniedHttpException::class);

        $this->provide();
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provide(Uuid::v4()->toRfc4122());
    }

    private function provide(?string $projectId = null): object
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->actor);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?IndustryProject => $id instanceof Uuid && $id->equals($this->project->getId()) ? $this->project : null,
        );
        $memberParticipant = (new IndustryProjectParticipant())->setUser($this->member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $participantRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $participantRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectParticipant => ($criteria['user'] ?? null) === $this->member ? $memberParticipant : null,
        );
        $inventedProductsReader = $this->createStub(InventedProductsReader::class);
        $inventedProductsReader->method('inventedProductsOf')->willReturn([
            self::ISHTAR => ['typeName' => 'Ishtar', 'manufacturingRuns' => 15],
            self::HAIL_L => ['typeName' => 'Hail L', 'manufacturingRuns' => 9],
        ]);

        $provider = new ProjectT2BlueprintsToProvideProvider($security, $projectRepository, new ProjectAccessChecker($participantRepository), $inventedProductsReader);

        return $provider->provide(new Get(), ['projectId' => $projectId ?? $this->project->getId()->toRfc4122()]);
    }

    private function pilot(string $name, int $eveCharacterId): User
    {
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }
}
