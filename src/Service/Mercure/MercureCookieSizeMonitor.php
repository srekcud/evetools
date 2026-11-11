<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

class MercureCookieSizeMonitor
{
    public const int BROWSER_COOKIE_LIMIT_BYTES = 4096;
    private const int WARNING_PERCENT_OF_LIMIT = 80;

    public function __construct(
        private readonly IndustryProjectParticipantRepository $participants,
        private readonly UserRepository $users,
        private readonly Authorization $authorization,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {
    }

    public function warningThresholdBytes(): int
    {
        return intdiv(self::BROWSER_COOKIE_LIMIT_BYTES * self::WARNING_PERCENT_OF_LIMIT, 100);
    }

    /**
     * @return list<MercureCookieUsage>
     */
    public function usersNearLimit(): array
    {
        $projectIdsByUser = $this->participants->findAcceptedProjectIdsByUser();
        if ($projectIdsByUser === []) {
            return [];
        }

        $request = Request::create($this->mercurePublicUrl);
        $threshold = $this->warningThresholdBytes();
        $usages = [];
        foreach ($this->users->findBy(['id' => array_keys($projectIdsByUser)]) as $user) {
            $userId = $user->getId()?->toRfc4122() ?? throw new \LogicException('A persisted user has no id.');
            $groupTopics = MercurePublisherService::getGroupTopics(
                $user->getCorporationId(),
                $user->getAllianceId(),
                $projectIdsByUser[$userId],
            );
            $cookie = $this->authorization->createCookie($request, [MercurePublisherService::getSubscriberGrant($userId, $groupTopics)]);
            $cookieBytes = strlen((string) $cookie);
            if ($cookieBytes < $threshold) {
                continue;
            }

            $usages[] = new MercureCookieUsage(
                $userId,
                $user->getMainCharacter()?->getName(),
                $cookieBytes,
                count(MercurePublisherService::getTopicsForUser($userId)) + count($groupTopics),
            );
        }

        usort($usages, static fn (MercureCookieUsage $first, MercureCookieUsage $second): int => $second->cookieBytes <=> $first->cookieBytes);

        return $usages;
    }
}
