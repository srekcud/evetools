<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Notification;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-271')]
final class NotificationPreferencesApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMiningOperationCategoryIsListedByDefaultAndCanBeDisabled(): void
    {
        $user = $this->persistPilot();
        $this->client->loginUser($user, 'api');

        $this->request('PUT', ['preferences' => [['category' => 'mining_operation', 'enabled' => false]]]);

        self::assertResponseIsSuccessful();
        $miningOperation = null;
        foreach ($this->responseJson()['preferences'] ?? [] as $preference) {
            if (($preference['category'] ?? null) === 'mining_operation') {
                $miningOperation = $preference;
            }
        }
        self::assertSame(
            ['category' => 'mining_operation', 'enabled' => false, 'thresholdMinutes' => null, 'pushEnabled' => false],
            $miningOperation === null ? null : [
                'category' => $miningOperation['category'],
                'enabled' => $miningOperation['enabled'],
                'thresholdMinutes' => $miningOperation['thresholdMinutes'] ?? null,
                'pushEnabled' => $miningOperation['pushEnabled'],
            ],
        );
    }

    private function persistPilot(): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId(2_112_271_001)
            ->setName('Ilsa Verhoeven')
            ->setCorporationId(98_271_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function request(string $method, array $body): void
    {
        $this->client->request(
            $method,
            '/api/me/notification-preferences',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
