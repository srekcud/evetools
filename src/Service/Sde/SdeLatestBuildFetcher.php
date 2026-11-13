<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SdeLatestBuildFetcher
{
    private const string LATEST_BUILD_URL = 'https://developers.eveonline.com/static-data/tranquility/latest.jsonl';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(): SdeBuild
    {
        try {
            $content = $this->httpClient->request('GET', self::LATEST_BUILD_URL)->getContent();
        } catch (HttpClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to read the latest SDE build from CCP: ' . $e->getMessage());
        }

        $build = SdeBuild::fromJsonLine($content);
        if ($build === null) {
            throw new \RuntimeException('Failed to read the latest SDE build from CCP: unexpected response');
        }

        return $build;
    }
}
