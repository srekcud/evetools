<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\JwtBlacklistListener;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(JwtBlacklistListener::class)]
class JwtBlacklistListenerTest extends TestCase
{
    private CacheItemPoolInterface $cache;
    private RequestStack $requestStack;
    private JwtBlacklistListener $listener;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(CacheItemPoolInterface::class);
        $this->requestStack = new RequestStack();

        $this->listener = new JwtBlacklistListener(
            $this->cache,
            $this->requestStack,
        );
    }

    public function testBlacklistedTokenMarksEventAsInvalid(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiJ9.test.payload';
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $this->requestStack->push($request);

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);

        $expectedCacheKey = 'jwt_blacklist_' . hash('sha256', $token);
        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key) use ($expectedCacheKey, $cacheItem) {
                $this->assertSame($expectedCacheKey, $key);

                return $cacheItem;
            });

        $event = $this->createMock(JWTDecodedEvent::class);
        $event->expects($this->once())->method('markAsInvalid');

        $this->listener->onJwtDecoded($event);
    }

    public function testNonBlacklistedTokenDoesNotInvalidate(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiJ9.valid.token';
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $this->requestStack->push($request);

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $event = $this->createMock(JWTDecodedEvent::class);
        $event->expects($this->never())->method('markAsInvalid');

        $this->listener->onJwtDecoded($event);
    }

    public function testNoRequestDoesNothing(): void
    {
        // RequestStack is empty (no current request)
        $event = $this->createMock(JWTDecodedEvent::class);
        $event->expects($this->never())->method('markAsInvalid');

        $this->listener->onJwtDecoded($event);
    }

    public function testNoAuthorizationHeaderDoesNothing(): void
    {
        $request = new Request();
        // No Authorization header
        $this->requestStack->push($request);

        $event = $this->createMock(JWTDecodedEvent::class);
        $event->expects($this->never())->method('markAsInvalid');

        $this->listener->onJwtDecoded($event);
    }

    public function testNonBearerAuthorizationHeaderDoesNothing(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
        $this->requestStack->push($request);

        $event = $this->createMock(JWTDecodedEvent::class);
        $event->expects($this->never())->method('markAsInvalid');

        $this->listener->onJwtDecoded($event);
    }

    public function testTokenHashUsesConsistentAlgorithm(): void
    {
        $token = 'test-token-value';
        $expectedHash = hash('sha256', $token);
        $expectedKey = 'jwt_blacklist_' . $expectedHash;

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $this->requestStack->push($request);

        $receivedKey = null;
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key) use (&$receivedKey, $cacheItem) {
                $receivedKey = $key;

                return $cacheItem;
            });

        $event = $this->createStub(JWTDecodedEvent::class);
        $this->listener->onJwtDecoded($event);

        $this->assertSame($expectedKey, $receivedKey);
    }
}
