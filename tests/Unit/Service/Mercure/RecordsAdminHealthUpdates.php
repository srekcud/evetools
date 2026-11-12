<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use App\Service\Mercure\MercurePublisherService;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

trait RecordsAdminHealthUpdates
{
    /** @var list<Update> */
    private array $publishedUpdates = [];
    private ?WarningCollector $warningCollector = null;

    private function adminHealthPublisher(?\Throwable $hubFailure = null): MercurePublisherService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($hubFailure): string {
            if (null !== $hubFailure) {
                throw $hubFailure;
            }
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });

        return new MercurePublisherService($hub, $this->warningCollector());
    }

    private function warningCollector(): WarningCollector
    {
        return $this->warningCollector ??= new WarningCollector();
    }

    /**
     * @return list<string>
     */
    private function publishedScopes(): array
    {
        return array_map(
            static fn (Update $update): string => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['adminHealth'],
            $this->publishedUpdates,
        );
    }
}
