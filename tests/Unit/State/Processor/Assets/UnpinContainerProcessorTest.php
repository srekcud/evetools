<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Assets\PinnedContainerResource;
use App\Entity\PinnedContainer;
use App\Entity\User;
use App\Repository\PinnedContainerRepository;
use App\State\Processor\Assets\UnpinContainerProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[CoversClass(UnpinContainerProcessor::class)]
#[Group('issue-893')]
final class UnpinContainerProcessorTest extends TestCase
{
    private const int ITEM_ID = 1_893_000_001;

    public function testUnpinningAManualPinRemovesIt(): void
    {
        $user = new User();
        $pin = new PinnedContainer($user, self::ITEM_ID, 'Sylvain - Reactions');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($pin);
        $entityManager->expects(self::once())->method('flush');

        $this->processor($user, $entityManager, $pin)->process($this->resource(null), new Delete());
    }

    public function testAContainerPinnedOnlyByAnActiveProjectCannotBeUnpinned(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');

        $this->expectException(ConflictHttpException::class);

        $this->processor(new User(), $entityManager, null)->process($this->resource('Capital rush'), new Delete());
    }

    private function resource(?string $projectName): PinnedContainerResource
    {
        $resource = new PinnedContainerResource();
        $resource->id = (string) self::ITEM_ID;
        $resource->itemId = self::ITEM_ID;
        $resource->projectName = $projectName;

        return $resource;
    }

    private function processor(User $user, EntityManagerInterface $entityManager, ?PinnedContainer $pin): UnpinContainerProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $repository = $this->createStub(PinnedContainerRepository::class);
        $repository->method('findByUserAndItemId')->willReturn($pin);

        return new UnpinContainerProcessor($security, $entityManager, $repository);
    }
}
