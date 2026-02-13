<?php

declare(strict_types=1);

namespace App\State\Provider\Mercure;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mercure\TokenResource;
use App\Entity\User;
use App\Service\Mercure\MercurePublisherService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<TokenResource>
 */
class TokenProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        #[Autowire('%env(MERCURE_JWT_SECRET)%')]
        private readonly string $mercureSecret,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TokenResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $userId = $user->getId()?->toRfc4122();
        if ($userId === null) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid user');
        }

        $topics = MercurePublisherService::getTopicsForUser($userId);
        $groupTopics = MercurePublisherService::getGroupTopics(
            $user->getCorporationId(),
            $user->getAllianceId(),
        );
        $topics = array_merge($topics, $groupTopics);
        $token = $this->createSubscriberJwt($topics);

        $resource = new TokenResource();
        $resource->token = $token;
        $resource->topics = $topics;
        $resource->hubUrl = $this->mercurePublicUrl;

        return $resource;
    }

    /**
     * Create a JWT for Mercure subscriber authentication.
     *
     * @param string[] $topics List of topics the subscriber can subscribe to
     */
    private function createSubscriberJwt(array $topics): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'mercure' => [
                'subscribe' => $topics,
            ],
            'exp' => time() + 3600, // 1 hour expiration
            'iat' => time(),
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            $this->mercureSecret,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
