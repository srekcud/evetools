<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SdeImportService
{
    private const SDE_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';
    private const DOWNLOAD_TIMEOUT = 600;

    public const VALID_SECTIONS = ['inventory', 'map', 'industry', 'dogma', 'reference', 'planetary'];

    private string $tempDir;
    private Filesystem $filesystem;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly SdeInventoryImporter $inventoryImporter,
        private readonly SdeMapImporter $mapImporter,
        private readonly SdeBlueprintImporter $blueprintImporter,
        private readonly SdeDogmaImporter $dogmaImporter,
        private readonly SdeReferenceImporter $referenceImporter,
        private readonly SdePlanetaryImporter $planetaryImporter,
        string $projectDir,
    ) {
        $this->tempDir = $projectDir . '/var/sde';
        $this->filesystem = new Filesystem();
    }

    /**
     * @param string[]|null $onlySections If set, only import these sections. null = all.
     */
    public function downloadAndImport(?callable $progressCallback = null, ?array $onlySections = null): void
    {
        $this->ensureTempDir();

        $this->notify($progressCallback, 'Downloading SDE from CCP...');
        $this->downloadSde();

        $this->configureTempDir();

        $importAll = $onlySections === null;

        if ($importAll || in_array('inventory', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing categories...');
            $this->inventoryImporter->importCategories($progressCallback);

            $this->notify($progressCallback, 'Importing groups...');
            $this->inventoryImporter->importGroups($progressCallback);

            $this->notify($progressCallback, 'Importing market groups...');
            $this->inventoryImporter->importMarketGroups($progressCallback);

            $this->notify($progressCallback, 'Importing types...');
            $this->inventoryImporter->importTypes($progressCallback);

            $this->notify($progressCallback, 'Importing type materials (reprocessing)...');
            $this->inventoryImporter->importTypeMaterials($progressCallback);
        }

        if ($importAll || in_array('map', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing regions...');
            $this->mapImporter->importRegions($progressCallback);

            $this->notify($progressCallback, 'Importing constellations...');
            $this->mapImporter->importConstellations($progressCallback);

            $this->notify($progressCallback, 'Importing solar systems...');
            $this->mapImporter->importSolarSystems($progressCallback);

            $this->notify($progressCallback, 'Importing stations...');
            $this->mapImporter->importStations($progressCallback);

            $this->notify($progressCallback, 'Importing stargates (solar system jumps)...');
            $this->mapImporter->importStargates($progressCallback);
        }

        if ($importAll || in_array('industry', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing blueprints and industry activities...');
            $this->blueprintImporter->importBlueprints($progressCallback);
        }

        if ($importAll || in_array('dogma', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing attribute types...');
            $this->dogmaImporter->importAttributeTypes($progressCallback);

            $this->notify($progressCallback, 'Importing type attributes...');
            $this->dogmaImporter->importTypeAttributes($progressCallback);

            $this->notify($progressCallback, 'Importing effects...');
            $this->dogmaImporter->importEffects($progressCallback);

            $this->notify($progressCallback, 'Importing type effects...');
            $this->dogmaImporter->importTypeEffects($progressCallback);
        }

        if ($importAll || in_array('reference', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing races...');
            $this->referenceImporter->importRaces($progressCallback);

            $this->notify($progressCallback, 'Importing factions...');
            $this->referenceImporter->importFactions($progressCallback);

            $this->notify($progressCallback, 'Importing flags...');
            $this->referenceImporter->importFlags($progressCallback);

            $this->notify($progressCallback, 'Importing icons...');
            $this->referenceImporter->importIcons($progressCallback);
        }

        if ($importAll || in_array('planetary', $onlySections, true)) {
            $this->notify($progressCallback, 'Importing planet schematics...');
            $this->planetaryImporter->importPlanetSchematics($progressCallback);
        }

        if ($importAll) {
            $this->notify($progressCallback, 'Cleaning up...');
            $this->cleanup();
        }

        $this->notify($progressCallback, 'Import completed successfully!');
    }

    private function configureTempDir(): void
    {
        $this->inventoryImporter->setTempDir($this->tempDir);
        $this->mapImporter->setTempDir($this->tempDir);
        $this->blueprintImporter->setTempDir($this->tempDir);
        $this->dogmaImporter->setTempDir($this->tempDir);
        $this->referenceImporter->setTempDir($this->tempDir);
        $this->planetaryImporter->setTempDir($this->tempDir);
    }

    private function ensureTempDir(): void
    {
        if (!$this->filesystem->exists($this->tempDir)) {
            $this->filesystem->mkdir($this->tempDir);
        }
    }

    private function downloadSde(): void
    {
        $zipPath = $this->tempDir . '/sde.zip';

        if ($this->filesystem->exists($this->tempDir . '/types.jsonl')) {
            $this->logger->info('SDE already extracted, skipping download');

            return;
        }

        if (!$this->filesystem->exists($zipPath)) {
            $this->logger->info('Downloading SDE from CCP...');

            try {
                $response = $this->httpClient->request('GET', self::SDE_URL, [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                ]);
                $this->filesystem->dumpFile($zipPath, $response->getContent());
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException('Failed to download SDE: ' . $e->getMessage());
            }
        }

        $this->logger->info('Extracting SDE...');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($this->tempDir);
            $zip->close();
        } else {
            throw new \RuntimeException('Failed to extract SDE zip file');
        }

        $this->filesystem->remove($zipPath);
    }

    private function cleanup(): void
    {
        $this->filesystem->remove($this->tempDir);
    }

    private function notify(?callable $callback, string $message): void
    {
        $this->logger->info($message);
        if ($callback) {
            $callback($message);
        }
    }
}
