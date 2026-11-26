<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-933')]
final class PlanetaryColonyLayoutRepositoryTest extends IntegrationTestCase
{
    private PlanetaryColonyLayoutRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PlanetaryColonyLayoutRepository::class);
    }

    public function testFindPublicReturnsThePublicLayoutsOfEveryUserMostRecentlyPublishedFirst(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $this->layout($alice, 'Published first', '2026-11-25T09:00:00+00:00', '2026-11-20T09:00:00+00:00');
        $this->layout($bob, 'Published last', '2026-11-01T09:00:00+00:00', '2026-11-24T09:00:00+00:00');
        $this->layout($alice, 'Published in the middle', '2026-11-10T09:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->flushAndClear();

        self::assertSame(['Published last', 'Published in the middle', 'Published first'], $this->namesOf($this->repository->findPublic(10)));
    }

    public function testFindPublicNeverReturnsAPrivateLayoutNorAnUnpublishedOne(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $this->layout($alice, 'Alice public', '2026-11-10T09:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->layout($alice, 'Alice private', '2026-11-11T09:00:00+00:00', null);
        $this->layout($bob, 'Bob private', '2026-11-12T09:00:00+00:00', null);
        $unpublished = $this->layout($bob, 'Bob unpublished', '2026-11-13T09:00:00+00:00', '2026-11-23T09:00:00+00:00');
        $unpublished->unpublish();
        $this->flushAndClear();

        self::assertSame(['Alice public'], $this->namesOf($this->repository->findPublic(10)));
    }

    public function testFindPublicKeepsTheMostRecentlyPublishedLayoutsWhenTheLimitIsReached(): void
    {
        $alice = $this->createUser();
        $this->layout($alice, 'Oldest', '2026-11-01T09:00:00+00:00', '2026-11-20T09:00:00+00:00');
        $this->layout($alice, 'Newest', '2026-11-02T09:00:00+00:00', '2026-11-24T09:00:00+00:00');
        $this->layout($alice, 'Second', '2026-11-03T09:00:00+00:00', '2026-11-23T09:00:00+00:00');
        $this->layout($alice, 'Third', '2026-11-04T09:00:00+00:00', '2026-11-22T09:00:00+00:00');
        $this->flushAndClear();

        self::assertSame(['Newest', 'Second'], $this->namesOf($this->repository->findPublic(2)));
        self::assertSame(['Newest'], $this->namesOf($this->repository->findPublic(1)));
    }

    public function testFindPublicReturnsNothingWhenNoLayoutIsPublic(): void
    {
        $this->layout($this->createUser(), 'Private only', '2026-11-01T09:00:00+00:00', null);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findPublic(10));
    }

    private function layout(User $user, string $name, string $createdAt, ?string $publishedAt): PlanetaryColonyLayout
    {
        $layout = (new PlanetaryColonyLayout())->setUser($user)->setName($name)->setTemplate(['Pln' => 991_016]);
        (new \ReflectionProperty(PlanetaryColonyLayout::class, 'createdAt'))->setValue($layout, new \DateTimeImmutable($createdAt));
        if ($publishedAt !== null) {
            $layout->publish(new \DateTimeImmutable($publishedAt));
        }
        $this->em->persist($layout);

        return $layout;
    }

    /**
     * @param list<PlanetaryColonyLayout> $layouts
     *
     * @return list<string>
     */
    private function namesOf(array $layouts): array
    {
        return array_map(static fn (PlanetaryColonyLayout $layout): string => $layout->getName(), $layouts);
    }
}
