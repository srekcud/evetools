<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Delete;
use App\Entity\User;
use App\State\Provider\Mining\FollowedRefineryProvider;
use App\Tests\Unit\Service\MoonChunk\StoresMoonRefineryFollows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(FollowedRefineryProvider::class)]
#[Group('issue-274')]
final class FollowedRefineryProviderTest extends TestCase
{
    use StoresMoonRefineryFollows;

    private const int CORPORATION_ID = 98_000_001;
    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const int DEEP_CORE_ID = 1_035_000_000_102;

    public function testARefineryNotFollowedByTheUserIsNotFoundEvenWhenAnotherUserFollowsIt(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $sora = $this->followerInCorporation(self::CORPORATION_ID, 'Sora Vantel');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, '2026-11-04T12:00:00+00:00');
        $this->storeFollow($sora, self::CORPORATION_ID, self::DEEP_CORE_ID, '2026-11-04T13:00:00+00:00');

        self::assertSame(
            [NotFoundHttpException::class, NotFoundHttpException::class],
            [$this->exceptionClassOf($ilyra, self::DEEP_CORE_ID), $this->exceptionClassOf($ilyra, 1_035_000_000_999)],
        );
    }

    private function exceptionClassOf(User $user, int $structureId): ?string
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        try {
            (new FollowedRefineryProvider($security, $this->followRepository()))->provide(new Delete(), ['structureId' => $structureId]);
        } catch (NotFoundHttpException $exception) {
            return $exception::class;
        }

        return null;
    }
}
