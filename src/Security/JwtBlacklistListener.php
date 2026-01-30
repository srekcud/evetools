<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded', method: 'onJwtDecoded')]
class JwtBlacklistListener
{
    public function __construct(
        private readonly CacheItemPoolInterface $jwtBlacklist,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return;
        }

        $authHeader = $request->headers->get('Authorization');

        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            return;
        }

        $token = substr($authHeader, 7);
        $tokenId = hash('sha256', $token);

        $cacheItem = $this->jwtBlacklist->getItem('jwt_blacklist_' . $tokenId);

        if ($cacheItem->isHit()) {
            $event->markAsInvalid();
        }
    }
}
