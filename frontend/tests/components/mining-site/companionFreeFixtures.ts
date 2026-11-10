import type { CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { corporation, extraction } from '../ledger/moonChunksHarness'

export const FREE_NOW = new Date('2026-11-05T19:00:00Z')
export const EXTRACTIONS_PATH = '/mining/corporation-extractions'
export const UNNAMED_MOON_ID = 40_009_001

export const CHUNK_A = extraction({
  id: '0195f3a0-2740-7000-8000-000000000a01',
  moonId: 40_009_101,
  moonName: 'Aldranette II - Moon 4',
  solarSystemId: 30_003_504,
  solarSystemName: 'Aldranette',
  chunkArrivalTime: '2026-11-05T22:00:00+00:00',
  naturalDecayTime: '2026-11-06T01:00:00+00:00',
})

export const CHUNK_B = extraction({
  id: '0195f3a0-2740-7000-8000-000000000b02',
  moonId: 40_009_102,
  moonName: 'Jaspet V - Moon 1',
  solarSystemId: 30_003_505,
  solarSystemName: 'Jaspet',
  chunkArrivalTime: '2026-11-05T20:05:00+00:00',
  naturalDecayTime: '2026-11-05T23:05:00+00:00',
})

export const CHUNK_C_EXPLODED = extraction({
  id: '0195f3a0-2740-7000-8000-000000000c03',
  moonId: 40_009_103,
  moonName: 'Kernite III - Moon 2',
  chunkArrivalTime: '2026-11-01T08:00:00+00:00',
  naturalDecayTime: '2026-11-01T11:00:00+00:00',
})

export const CHUNK_READY = extraction({
  id: '0195f3a0-2740-7000-8000-000000000d04',
  moonId: 40_009_104,
  moonName: 'Plagioclase I - Moon 7',
  chunkArrivalTime: '2026-11-05T17:30:00+00:00',
  naturalDecayTime: '2026-11-05T20:30:00+00:00',
})

export const CHUNK_UNNAMED = extraction({
  id: '0195f3a0-2740-7000-8000-000000000e05',
  moonId: UNNAMED_MOON_ID,
  moonName: null,
  solarSystemId: null,
  solarSystemName: null,
  chunkArrivalTime: '2026-11-05T20:05:00+00:00',
  naturalDecayTime: '2026-11-05T23:05:00+00:00',
})

export const CHUNK_TIED_WITH_B = extraction({
  id: '0195f3a0-2740-7000-8000-000000000f06',
  moonId: 40_009_106,
  moonName: 'Scordite IV - Moon 3',
  chunkArrivalTime: '2026-11-05T20:05:00+00:00',
  naturalDecayTime: '2026-11-05T23:05:00+00:00',
})

export const FIRST_CORPORATION = 98_262_101
export const SECOND_CORPORATION = 98_262_202

export function corporationsOf(...groups: CorporationMiningExtraction[][]) {
  return groups.map((extractions, index) => corporation({ corporationId: index === 0 ? FIRST_CORPORATION : SECOND_CORPORATION, extractions }))
}
