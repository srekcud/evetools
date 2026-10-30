<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Pve\CorpProjectContributionAccounting;
use App\Service\Pve\CorpProjectContributionReader;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait CorpProjectReaderWithoutEsi
{
    private function corpProjectReaderWithoutEsi(PveIncomeRepository $incomeRepository, UserPveSettingsRepository $settingsRepository): CorpProjectContributionReader
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $failingEsi = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->fail(sprintf('Unexpected corporation project request: %s %s', $method, $url));
        });

        return new CorpProjectContributionReader(
            esiClient: new EsiClient($failingEsi, new TagAwareAdapter(new ArrayAdapter()), $tokenManager, 'https://esi.test/latest', new NullLogger()),
            tokenManager: $tokenManager,
            cache: new ArrayAdapter(),
            logger: new NullLogger(),
            contributionAccounting: new CorpProjectContributionAccounting(
                incomeRepository: $incomeRepository,
                settingsRepository: $settingsRepository,
            ),
        );
    }
}
