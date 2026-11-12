<?php

declare(strict_types=1);

namespace App\State\Provider\Mercure;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mercure\TokenResource;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;
use App\Security\AdminChecker;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\Service\Mercure\MercurePublisherService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\Authorization;

/**
 * @implements ProviderInterface<TokenResource>
 */
class TokenProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly Authorization $authorization,
        private readonly AdminChecker $adminChecker,
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
        $groupProjectIds = $this->memberRepository->findAcceptedProjectIds($user);
        $groupTopics = MercurePublisherService::getGroupTopics(
            $user->getCorporationId(),
            $user->getAllianceId(),
            $groupProjectIds,
        );
        $exactTopics = $this->adminChecker->isAdmin($user)
            ? [...$groupTopics, ...MercurePublisherService::getAdminTopics()]
            : $groupTopics;
        $topics = array_merge($topics, $exactTopics);
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new \LogicException('The Mercure token needs the current request to set the authorization cookie.');
        }

        $this->authorization->setCookie($request, [MercurePublisherService::getSubscriberGrant($userId, $exactTopics)]);

        $cookie = $request->attributes->get('_mercure_authorization_cookies')[''];
        if (strlen((string) $cookie) > MercureCookieSizeMonitor::BROWSER_COOKIE_LIMIT_BYTES) {
            throw new \LengthException(sprintf('The Mercure authorization cookie of user %s needs %d bytes, browsers keep at most %d.', $userId, strlen((string) $cookie), MercureCookieSizeMonitor::BROWSER_COOKIE_LIMIT_BYTES));
        }

        $resource = new TokenResource();
        $resource->token = $cookie->getValue();
        $resource->topics = $topics;
        $resource->hubUrl = $this->mercurePublicUrl;

        return $resource;
    }
}
