import type {
  CorporationMiningExtraction,
  MoonChunkValue,
  CorporationMiningExtractions,
  CorporationMiningExtractionsResponse,
} from '@/composables/useCorporationMiningExtractions'

export const NOW = '2026-11-05T19:00:00Z'
export const CORPORATION_A = 98000001
export const CORPORATION_B = 98000002
export const UNNAMED_MOON_ID = 40302003
export const UNNAMED_STRUCTURE_ID = 1035000000201
export const LONG_STRUCTURE_NAME = 'Ihakana - Deep Core Tatara Of The Southern Belt Mining Consortium'

export function extraction(overrides: Partial<CorporationMiningExtraction>): CorporationMiningExtraction {
  return {
    id: 'extraction',
    structureId: 1035000000101,
    structureName: 'NOL-M9 - Rock Hollow',
    solarSystemId: 30004712,
    solarSystemName: 'NOL-M9',
    moonId: 40301001,
    moonName: 'NOL-M9 VII - Moon 3',
    extractionStartTime: '2026-11-02T18:00:00+00:00',
    chunkArrivalTime: '2026-11-05T20:00:00+00:00',
    naturalDecayTime: '2026-11-05T23:00:00+00:00',
    status: 'extracting',
    chunkValue: null,
    ...overrides,
  }
}

export const READY_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000103',
  structureId: 1035000000102,
  structureName: LONG_STRUCTURE_NAME,
  solarSystemId: 30003135,
  solarSystemName: 'Ihakana',
  moonId: UNNAMED_MOON_ID,
  moonName: null,
  extractionStartTime: '2026-10-29T17:00:00+00:00',
  chunkArrivalTime: '2026-11-05T17:00:00+00:00',
  naturalDecayTime: '2026-11-05T20:00:00+00:00',
  status: 'ready',
})

export const ROCK_HOLLOW_CHUNK = extraction({ id: '0195f3a0-2740-7000-8000-000000000101' })

export const DEEP_CORE_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000102',
  structureId: 1035000000102,
  structureName: LONG_STRUCTURE_NAME,
  solarSystemId: 30003135,
  solarSystemName: 'Ihakana',
  moonId: 40302002,
  moonName: 'Ihakana II - Moon 1',
  extractionStartTime: '2026-10-30T12:00:00+00:00',
  chunkArrivalTime: '2026-11-08T06:15:00+00:00',
  naturalDecayTime: '2026-11-08T09:15:00+00:00',
  status: 'extracting',
})

export const EXPLODED_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000104',
  extractionStartTime: '2026-10-14T19:00:00+00:00',
  chunkArrivalTime: '2026-10-20T19:00:00+00:00',
  naturalDecayTime: '2026-10-23T19:00:00+00:00',
  status: 'exploded',
})

export const DENIED_HISTORY_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000201',
  structureId: UNNAMED_STRUCTURE_ID,
  structureName: null,
  solarSystemId: null,
  solarSystemName: null,
  moonId: 40401004,
  moonName: 'Ostingele IV - Moon 2',
  extractionStartTime: '2026-11-01T08:00:00+00:00',
  chunkArrivalTime: '2026-11-07T08:00:00+00:00',
  naturalDecayTime: '2026-11-07T11:00:00+00:00',
  status: 'extracting',
})

export function corporation(overrides: Partial<CorporationMiningExtractions>): CorporationMiningExtractions {
  return {
    corporationId: CORPORATION_A,
    access: 'ok',
    lastSuccessAt: '2026-11-05T18:30:00+00:00',
    extractions: [],
    ...overrides,
  }
}

export const SYNCED_CORPORATION = corporation({
  extractions: [EXPLODED_CHUNK, READY_CHUNK, ROCK_HOLLOW_CHUNK, DEEP_CORE_CHUNK],
})

export const DENIED_CORPORATION = corporation({
  corporationId: CORPORATION_B,
  access: 'denied',
  lastSuccessAt: '2026-11-04T10:00:00+00:00',
  extractions: [DENIED_HISTORY_CHUNK],
})

export function response(...corporations: CorporationMiningExtractions[]): CorporationMiningExtractionsResponse {
  return { corporations }
}

export const LONG_MOON_NAME = 'Ihakana II - Moon 1 of the Southern Belt Mining Consortium Annex of the Deep Core Tatara'

export const KNOWN_VALUE: MoonChunkValue = {
  status: 'known',
  reason: null,
  volumeM3: 1_850_000,
  totalValue: 40_700_000,
  perParticipant: [
    { participants: 4, value: 10_175_000 },
    { participants: 6, value: 6_783_333.33 },
    { participants: 8, value: 5_087_500 },
  ],
  lines: [
    { typeId: 45492, typeName: 'Bitumens', share: 0.4, units: 74_000, value: 7_400_000 },
    { typeId: 45501, typeName: 'Chromite', share: 0.6, units: 111_000, value: 33_300_000 },
  ],
  scanRecordedAt: '2026-11-04T09:30:00+00:00',
}

export const NO_SCAN_VALUE: MoonChunkValue = {
  status: 'unknown',
  reason: 'no_scan',
  volumeM3: 1_850_000,
  totalValue: null,
  perParticipant: null,
  lines: null,
  scanRecordedAt: null,
}

export const NO_PRICE_VALUE: MoonChunkValue = {
  ...NO_SCAN_VALUE,
  reason: 'no_price',
  scanRecordedAt: '2026-11-04T09:30:00+00:00',
}

export const VALUED_CHUNK = extraction({ id: '0195f3a0-2740-7000-8000-000000000301', moonId: 40301011, chunkValue: KNOWN_VALUE })

export const NO_SCAN_CHUNK = extraction({ id: '0195f3a0-2740-7000-8000-000000000302', moonId: 40301012, moonName: 'NOL-M9 VII - Moon 5', chunkValue: NO_SCAN_VALUE })

export const NO_PRICE_CHUNK = extraction({ id: '0195f3a0-2740-7000-8000-000000000303', moonId: 40301013, moonName: 'NOL-M9 VII - Moon 6', chunkValue: NO_PRICE_VALUE })

export const LONG_MOON_CHUNK = extraction({ id: '0195f3a0-2740-7000-8000-000000000304', moonId: 40301014, moonName: LONG_MOON_NAME, chunkValue: NO_SCAN_VALUE })
