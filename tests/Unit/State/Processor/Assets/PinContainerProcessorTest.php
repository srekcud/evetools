<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Assets\PinnedContainerResource;
use App\ApiResource\Input\Assets\PinContainerInput;
use App\Entity\CachedAsset;
use App\Entity\PinnedContainer;
use App\Entity\User;
use App\Repository\PinnedContainerRepository;
use App\Service\Assets\PinnedContainerCatalog;
use App\Service\Assets\PinnedContainerEntry;
use App\State\Processor\Assets\PinContainerProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(PinContainerProcessor::class)]
#[Group('issue-893')]
final class PinContainerProcessorTest extends TestCase
{
    private const int ITEM_ID = 1_893_000_001;
    private const string CONTAINER_NAME = 'Sylvain - Reactions';

    public function testPinningAVisibleContainerStoresItWithItsCurrentName(): void
    {
        $user = new User();
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $pin) use (&$persisted): void {
            $persisted[] = $pin;
        });
        $entityManager->expects(self::once())->method('flush');

        $resource = $this->processor($user, $entityManager, $this->visibleContainer(), null)->process($this->input(), new Post());

        self::assertCount(1, $persisted);
        self::assertInstanceOf(PinnedContainer::class, $persisted[0]);
        self::assertSame($user, $persisted[0]->getUser());
        self::assertSame(self::ITEM_ID, $persisted[0]->getItemId());
        self::assertSame(self::CONTAINER_NAME, $persisted[0]->getName());
        self::assertInstanceOf(PinnedContainerResource::class, $resource);
        self::assertSame(self::ITEM_ID, $resource->itemId);
    }

    public function testPinningAContainerTheUserCannotSeeIsNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $this->expectException(NotFoundHttpException::class);

        $this->processor(new User(), $entityManager, null, null)->process($this->input(), new Post());
    }

    public function testPinningAnAlreadyPinnedContainerStoresNothingMore(): void
    {
        $user = new User();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $resource = $this->processor($user, $entityManager, $this->visibleContainer(), new PinnedContainer($user, self::ITEM_ID, self::CONTAINER_NAME))->process($this->input(), new Post());

        self::assertSame(self::ITEM_ID, $resource->itemId);
    }

    private function input(): PinContainerInput
    {
        $input = new PinContainerInput();
        $input->itemId = self::ITEM_ID;

        return $input;
    }

    private function visibleContainer(): CachedAsset
    {
        return (new CachedAsset())->setItemId(self::ITEM_ID)->setItemName(self::CONTAINER_NAME)->setTypeName('Giant Secure Container');
    }

    private function processor(User $user, EntityManagerInterface $entityManager, ?CachedAsset $visibleAsset, ?PinnedContainer $existingPin): PinContainerProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $repository = $this->createStub(PinnedContainerRepository::class);
        $repository->method('findByUserAndItemId')->willReturn($existingPin);
        $catalog = $this->createStub(PinnedContainerCatalog::class);
        $catalog->method('visibleAssetOf')->willReturn($visibleAsset);
        $catalog->method('entriesOf')->willReturn([
            new PinnedContainerEntry((string) self::ITEM_ID, self::ITEM_ID, self::CONTAINER_NAME, 'Jita IV - Moon 4', 'Jita', 'Reactions', 3, true, null, null),
        ]);

        return new PinContainerProcessor($security, $entityManager, $repository, $catalog);
    }
}
