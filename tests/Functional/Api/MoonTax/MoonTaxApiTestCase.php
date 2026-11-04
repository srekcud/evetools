<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\User;
use App\Tests\Integration\MoonTax\SeedsMoonTax;
use App\Tests\Integration\MoonTax\StubsMoonTaxEsi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

abstract class MoonTaxApiTestCase extends WebTestCase
{
    use ClockSensitiveTrait;
    use SeedsMoonTax;
    use StubsMoonTaxEsi;

    protected const string NOW = '2026-11-10T12:00:00+00:00';
    protected const string ISO_FORMAT = \DateTimeInterface::ATOM;
    protected const string BASE_PATH = '/api/mining/moon-tax';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->replaceEsi();
        $this->replaceMoonOreJitaMarket();
        $this->seedMoonOreSde();
        $this->respondWithCharacterNames([self::DAX_ID => 'Dax Orlen', self::MIRA_ID => 'Mira Venn']);
    }

    protected function accountant(): User
    {
        $this->persistCharacterOfCorporation('Ilyra Voss', self::ILYRA_ID, self::CORPORATION, ['Accountant']);
        $this->em->flush();

        return $this->usersByEveCharacterId[self::ILYRA_ID];
    }

    protected function request(User $user, string $method, string $uri, ?array $body = null, string $accept = 'application/json'): void
    {
        $this->em->flush();
        $this->em->clear();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => $accept],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
        $this->em->clear();
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function responseJson(): array
    {
        $decoded = $this->content() === '' ? [] : json_decode($this->content(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    protected function utc(?\DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT);
    }
}
