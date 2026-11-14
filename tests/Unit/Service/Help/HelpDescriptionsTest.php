<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Help;

use App\Scheduler\SyncScheduler;
use App\Service\ESI\AuthenticationService;
use App\Service\Help\HelpDescriptionMissing;
use App\Service\Help\HelpDescriptions;
use App\Service\Help\ScheduledSyncReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(HelpDescriptions::class)]
final class HelpDescriptionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        foreach (HelpDescriptions::LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryRequiredScopeHasANonEmptyDescription(string $language): void
    {
        $descriptions = new HelpDescriptions();

        foreach (AuthenticationService::getRequiredScopes() as $scope) {
            self::assertNotSame('', trim($descriptions->scopeUsage($scope, $language)), \sprintf('%s (%s)', $scope, $language));
        }
    }

    #[DataProvider('languages')]
    public function testEveryScheduledMessageHasANonEmptyLabel(string $language): void
    {
        $descriptions = new HelpDescriptions();

        foreach ($this->scheduledMessageClasses() as $messageClass) {
            self::assertNotSame('', trim($descriptions->syncLabel($messageClass, $language)), \sprintf('%s (%s)', $messageClass, $language));
        }
    }

    public function testNoDescriptionIsLeftForAScopeThatIsNoLongerRequired(): void
    {
        $required = AuthenticationService::getRequiredScopes();
        $described = (new HelpDescriptions())->describedScopes();

        self::assertSame([], array_values(array_diff($described, $required)));
        self::assertSame([], array_values(array_diff($required, $described)));
    }

    public function testNoDescriptionIsLeftForAMessageThatIsNoLongerScheduled(): void
    {
        $scheduled = $this->scheduledMessageClasses();
        $described = (new HelpDescriptions())->describedSyncMessages();

        self::assertSame([], array_values(array_diff($described, $scheduled)));
        self::assertSame([], array_values(array_diff($scheduled, $described)));
    }

    #[DataProvider('languages')]
    public function testOnDemandSyncsHaveANonEmptyLabel(string $language): void
    {
        $labels = (new HelpDescriptions())->onDemandSyncLabels($language);

        self::assertNotSame([], $labels);
        foreach ($labels as $label) {
            self::assertNotSame('', trim($label));
        }
    }

    public function testAMissingLanguageRaisesAnErrorNamingTheKeyAndTheLanguage(): void
    {
        $descriptions = new HelpDescriptions(['scope-a' => ['fr' => 'Usage']], [], []);

        $this->expectException(HelpDescriptionMissing::class);
        $this->expectExceptionMessage('scope-a');

        $descriptions->scopeUsage('scope-a', 'en');
    }

    public function testAnEmptyDescriptionRaisesAnError(): void
    {
        $descriptions = new HelpDescriptions(['scope-a' => ['fr' => 'Usage', 'en' => '  ']], [], []);

        $this->expectException(HelpDescriptionMissing::class);

        $descriptions->scopeUsage('scope-a', 'en');
    }

    public function testAnUnknownKeyRaisesAnErrorForSyncLabelsToo(): void
    {
        $descriptions = new HelpDescriptions([], [], []);

        $this->expectException(HelpDescriptionMissing::class);
        $this->expectExceptionMessage('App\Message\Unknown');

        $descriptions->syncLabel('App\Message\Unknown', 'fr');
    }

    /** @return list<string> */
    private function scheduledMessageClasses(): array
    {
        $reader = new ScheduledSyncReader([new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore()))]);

        return array_map(static fn ($sync): string => $sync->messageClass, $reader->read());
    }
}
