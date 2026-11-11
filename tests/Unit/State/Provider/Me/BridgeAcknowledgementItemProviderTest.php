<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\Get;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use App\State\Provider\Me\BridgeAcknowledgementItemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(BridgeAcknowledgementItemProvider::class)]
#[Group('issue-627')]
class BridgeAcknowledgementItemProviderTest extends TestCase
{
    private const int EXPIRING_EXTRACTORS_COUNT = 3;

    public function testReturnsTheAcknowledgementOfTheCurrentUser(): void
    {
        $user = $this->createStub(User::class);
        $provider = $this->providerFor($user, [$this->acknowledgementOf($user, BridgeTodoKey::ExpiringExtractors, self::EXPIRING_EXTRACTORS_COUNT)]);

        $resource = $provider->provide(new Get(), ['itemKey' => 'expiringExtractors']);

        $this->assertSame('expiringExtractors', $resource->itemKey);
        $this->assertSame(self::EXPIRING_EXTRACTORS_COUNT, $resource->acknowledgedCount);
    }

    public function testThrowsNotFoundWhenTheKeyIsNotAcknowledged(): void
    {
        $user = $this->createStub(User::class);
        $provider = $this->providerFor($user, []);

        $this->expectException(NotFoundHttpException::class);

        $provider->provide(new Get(), ['itemKey' => 'expiringExtractors']);
    }

    public function testThrowsNotFoundForTheAcknowledgementOfAnotherUser(): void
    {
        $user = $this->createStub(User::class);
        $otherUser = $this->createStub(User::class);
        $provider = $this->providerFor($user, [$this->acknowledgementOf($otherUser, BridgeTodoKey::ExpiringExtractors, self::EXPIRING_EXTRACTORS_COUNT)]);

        $this->expectException(NotFoundHttpException::class);

        $provider->provide(new Get(), ['itemKey' => 'expiringExtractors']);
    }

    public function testThrowsNotFoundForAKeyOutsideTheAcknowledgeableOnes(): void
    {
        $user = $this->createStub(User::class);
        $provider = $this->providerFor($user, []);

        $this->expectException(NotFoundHttpException::class);

        $provider->provide(new Get(), ['itemKey' => 'charactersToReconnect']);
    }

    /**
     * @param BridgeAcknowledgement[] $stored
     */
    private function providerFor(User $user, array $stored): BridgeAcknowledgementItemProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repository = $this->createStub(BridgeAcknowledgementRepository::class);
        $repository->method('findByUserAndItemKey')->willReturnCallback(
            static function (User $owner, BridgeTodoKey $itemKey) use ($stored): ?BridgeAcknowledgement {
                foreach ($stored as $acknowledgement) {
                    if ($acknowledgement->getUser() === $owner && $acknowledgement->getItemKey() === $itemKey) {
                        return $acknowledgement;
                    }
                }

                return null;
            },
        );

        return new BridgeAcknowledgementItemProvider($security, $repository);
    }

    private function acknowledgementOf(User $user, BridgeTodoKey $itemKey, int $count): BridgeAcknowledgement
    {
        return (new BridgeAcknowledgement())->setUser($user)->setItemKey($itemKey)->setAcknowledgedCount($count);
    }
}
