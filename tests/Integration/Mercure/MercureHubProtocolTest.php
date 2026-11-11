<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mercure;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\ProtocolVersion;

#[Group('issue-613')]
final class MercureHubProtocolTest extends KernelTestCase
{
    public function testTheHubSpeaksTheMercureProtocolOne(): void
    {
        self::assertSame(ProtocolVersion::V1, $this->hub()->getProtocolVersion());
    }

    public function testTheHubTokensAreAccessTokensBoundToTheConfiguredResourceIdentifier(): void
    {
        $token = $this->hub()->getFactory()->create([new Grant([Grant::ACTION_PUBLISH], ['*'])]);

        [$header, $payload] = array_map(
            static fn (string $segment): array => json_decode(base64_decode(strtr($segment, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR),
            array_slice(explode('.', $token), 0, 2),
        );
        $resourceIdentifier = $_SERVER['MERCURE_RESOURCE_IDENTIFIER'] ?? $_ENV['MERCURE_RESOURCE_IDENTIFIER'];
        self::assertSame('at+jwt', $header['typ']);
        self::assertSame($resourceIdentifier, $payload['iss']);
        self::assertSame([$resourceIdentifier], (array) $payload['aud']);
        self::assertNotEmpty($payload['sub']);
        self::assertNotEmpty($payload['client_id']);
        self::assertSame('*', $payload['authorization_details'][0]['topics'][0]['match']);
    }

    public function testTheHubReadsTheSubscriberCookieFromTheSecurePrefixedName(): void
    {
        self::assertSame('__Secure-mercure_access_token', $this->hub()->getCookieName());
    }

    private function hub(): HubInterface
    {
        return self::getContainer()->get(HubInterface::class);
    }
}
