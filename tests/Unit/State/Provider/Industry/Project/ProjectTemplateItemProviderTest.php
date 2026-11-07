<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Patch;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Provider\Industry\Project\ProjectTemplateItemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ProjectTemplateItemProvider::class)]
#[Group('issue-355')]
final class ProjectTemplateItemProviderTest extends TestCase
{
    use BuildsProjectTemplates;

    private const int CORPORATION_ID = 98_000_001;

    public function testOwnerReceivesTheTemplateResource(): void
    {
        $alice = $this->pilot('Alice Ferrand', self::CORPORATION_ID);
        $template = $this->templateOf($alice, 'Hammerhead line', false);

        $resource = $this->providerFor($alice, [$template])->provide(new Patch(), ['id' => (string) $template->getId()]);

        self::assertSame((string) $template->getId(), $resource->id);
        self::assertSame('Hammerhead line', $resource->name);
        self::assertTrue($resource->isOwn);
    }

    public function testTemplateThatIsNotVisibleToTheUserIsNotFound(): void
    {
        $alice = $this->pilot('Alice Ferrand', self::CORPORATION_ID);
        $carol = $this->pilot('Carol Dumas', 98_000_002);
        $template = $this->templateOf($alice, 'Hammerhead line', true);

        $this->expectException(NotFoundHttpException::class);

        $this->providerFor($carol, [])->provide(new Patch(), ['id' => (string) $template->getId()]);
    }

    public function testVisibleTemplateOfAnotherUserIsRefusedToTheNonOwner(): void
    {
        $alice = $this->pilot('Alice Ferrand', self::CORPORATION_ID);
        $bob = $this->pilot('Bob Lenoir', self::CORPORATION_ID);
        $template = $this->templateOf($alice, 'Hammerhead line', true);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the template owner can perform this action');

        $this->providerFor($bob, [$template])->provide(new Patch(), ['id' => (string) $template->getId()]);
    }

    /** @param list<IndustryProjectTemplate> $visibleTemplates */
    private function providerFor(User $actor, array $visibleTemplates): ProjectTemplateItemProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);
        $repository = $this->createStub(IndustryProjectTemplateRepository::class);
        $repository->method('findVisibleTo')->willReturn($visibleTemplates);

        return new ProjectTemplateItemProvider($security, $repository);
    }
}
