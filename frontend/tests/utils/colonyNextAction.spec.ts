import type { Colony, PlanetaryInputNeeds } from '@/stores/planetary'
import {
  REFILL_HORIZON_HOURS,
  filterColonies,
  filterCounts,
  nextActionOf,
  sortColonies,
} from '@/utils/colonyNextAction'

const HOUR_MS = 3_600_000
const NOW = Date.parse('2026-11-26T09:00:00+00:00')

function at(hoursFromNow: number): string {
  return new Date(NOW + hoursFromNow * HOUR_MS).toISOString()
}

function instant(hoursFromNow: number): number {
  return NOW + hoursFromNow * HOUR_MS
}

function colony(overrides: Partial<Colony>): Colony {
  return {
    id: 'colony',
    characterId: 2113830001,
    characterName: 'Ilyra Castell',
    planetId: 40009077,
    planetName: 'Jita IV',
    planetType: 'barren',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    solarSystemSecurity: 0.9,
    upgradeLevel: 4,
    tier: null,
    storageFull: null,
    commandCenterUpgradable: false,
    numPins: 12,
    lastUpdate: at(-6),
    cachedAt: at(-6),
    extractorCount: 0,
    factoryCount: 0,
    activeExtractors: 0,
    nearestExpiry: null,
    factoryProgress: null,
    status: 'idle',
    pins: [],
    routes: [],
    ...overrides,
  }
}

function inputNeeds(emptyAtByColonyId: Record<string, (string | null)[][]>): PlanetaryInputNeeds {
  return {
    complete: true,
    launchpads: Object.entries(emptyAtByColonyId).flatMap(([colonyId, launchpads]) =>
      launchpads.map((emptyAts, index) => ({
        colonyId,
        pinId: 1000 + index,
        feeds: emptyAts.map((emptyAt, feedIndex) => ({ typeId: 2393 + feedIndex, typeName: null, emptyAt })),
      })),
    ),
  }
}

function ids(colonies: Colony[]): string[] {
  return colonies.map(item => item.id)
}

describe('colony next action', () => {
  it('reads a restart from the nearest expiry of a colony with an extractor, a refill from its earliest launchpad emptyAt and a storage_full at its last view', () => {
    const extracting = colony({ id: 'extracting', extractorCount: 2, nearestExpiry: at(5) })
    const feeding = colony({ id: 'feeding', factoryCount: 6 })
    const full = colony({ id: 'full', storageFull: true, lastUpdate: at(-3) })
    const calm = colony({ id: 'calm', tier: 'P4' })
    const needs = inputNeeds({
      feeding: [[at(12), null, at(9)], [at(30)]],
      stranger: [[at(1)]],
    })

    expect(nextActionOf(extracting, needs)).toEqual({ type: 'restart', at: instant(5) })
    expect(nextActionOf(feeding, needs)).toEqual({ type: 'refill', at: instant(9) })
    expect(nextActionOf(full, needs)).toEqual({ type: 'storage_full', at: instant(-3) })
    expect(nextActionOf(calm, needs)).toBeNull()
  })

  it('ignores an expiry on a colony without extractor and a launchpad that never empties', () => {
    const withoutExtractor = colony({ id: 'without-extractor', extractorCount: 0, nearestExpiry: at(5) })
    const neverEmpties = colony({ id: 'never-empties', factoryCount: 3 })

    expect(nextActionOf(withoutExtractor, inputNeeds({}))).toBeNull()
    expect(nextActionOf(neverEmpties, inputNeeds({ 'never-empties': [[null]] }))).toBeNull()
  })

  it('takes the nearest action in time, a refill already late beating an extractor finishing in 5 hours', () => {
    const extractorAndLaunchpad = colony({ id: 'extractor-then-launchpad', extractorCount: 2, nearestExpiry: at(5) })
    const lateLaunchpadAndExtractor = colony({ id: 'late-launchpad', extractorCount: 2, nearestExpiry: at(5) })
    const needs = inputNeeds({
      'extractor-then-launchpad': [[at(9)]],
      'late-launchpad': [[at(-1)]],
    })

    expect(nextActionOf(extractorAndLaunchpad, needs)).toEqual({ type: 'restart', at: instant(5) })
    expect(nextActionOf(lateLaunchpadAndExtractor, needs)).toEqual({ type: 'refill', at: instant(-1) })
  })

  it('invents no refill action when the input needs are unknown and falls back on the other actions', () => {
    const extractingAndFeeding = colony({ id: 'extracting-feeding', extractorCount: 2, nearestExpiry: at(5), factoryCount: 4 })
    const onlyFeeding = colony({ id: 'only-feeding', factoryCount: 4 })

    expect(nextActionOf(extractingAndFeeding, null)).toEqual({ type: 'restart', at: instant(5) })
    expect(nextActionOf(onlyFeeding, null)).toBeNull()
  })
})

describe('colony sort', () => {
  const extractor = colony({ id: 'extractor', extractorCount: 2, nearestExpiry: at(5), planetName: 'Jita IV', solarSystemName: 'Jita', characterName: 'Ilyra Castell' })
  const twinExtractor = colony({ id: 'twin-extractor', extractorCount: 1, nearestExpiry: at(5), planetName: 'Jita V', solarSystemName: 'Jita', characterName: 'Ilyra Castell' })
  const lateRefill = colony({ id: 'late-refill', factoryCount: 6, planetName: 'Perimeter II', solarSystemName: 'Perimeter', characterName: 'Doran Vex' })
  const storageFull = colony({ id: 'storage-full', storageFull: true, lastUpdate: at(-3), planetName: 'Perimeter III', solarSystemName: 'Perimeter', characterName: 'Doran Vex' })
  const calm = colony({ id: 'calm', tier: 'P4', planetName: 'Amamake I', solarSystemName: 'Amamake', characterName: 'Sera Idle' })
  const list = [calm, extractor, lateRefill, storageFull, twinExtractor]
  const needs = inputNeeds({ 'late-refill': [[at(-1)]] })

  it('orders by the date of the next action, past first, colonies without action last and ties in the original order', () => {
    expect(ids(sortColonies(list, 'nextAction', needs))).toEqual(['storage-full', 'late-refill', 'extractor', 'twin-extractor', 'calm'])
  })

  it('keeps the original order by character and orders by system name then original order by system', () => {
    expect(ids(sortColonies(list, 'character', needs))).toEqual(['calm', 'extractor', 'late-refill', 'storage-full', 'twin-extractor'])
    expect(ids(sortColonies(list, 'system', needs))).toEqual(['calm', 'extractor', 'twin-extractor', 'late-refill', 'storage-full'])
  })
})

describe('colony quick filters', () => {
  const colonies = [
    colony({ id: 'expired-p1', status: 'expired', tier: 'P1', extractorCount: 2, nearestExpiry: at(-3) }),
    colony({ id: 'expiring-p2', status: 'expiring', tier: 'P2', extractorCount: 1, nearestExpiry: at(2) }),
    colony({ id: 'upgradable-p4', status: 'active', tier: 'P4', commandCenterUpgradable: true, extractorCount: 3, nearestExpiry: at(70) }),
    colony({ id: 'full-p1', status: 'factory', tier: 'P1', storageFull: true, commandCenterUpgradable: true }),
    colony({ id: 'refill-soon-p3', status: 'factory', tier: 'P3' }),
    colony({ id: 'refill-late-p2', status: 'factory', tier: 'P2' }),
    colony({ id: 'refill-far-p4', status: 'factory', tier: 'P4' }),
    colony({ id: 'unknown-p1', status: 'idle', tier: 'P1', storageFull: null, commandCenterUpgradable: null }),
    colony({ id: 'no-tier', status: 'idle' }),
  ]
  const needs = inputNeeds({
    'refill-soon-p3': [[at(REFILL_HORIZON_HOURS - 1)]],
    'refill-late-p2': [[at(-1)]],
    'refill-far-p4': [[at(REFILL_HORIZON_HOURS + 1)]],
  })

  it('keeps the colonies matching the filter: tier field, extractor status, refill within the horizon or late, storage full, command center to raise', () => {
    expect(ids(filterColonies(colonies, 'all', needs, NOW))).toHaveLength(9)
    expect(ids(filterColonies(colonies, 'P1', needs, NOW))).toEqual(['expired-p1', 'full-p1', 'unknown-p1'])
    expect(ids(filterColonies(colonies, 'P2', needs, NOW))).toEqual(['expiring-p2', 'refill-late-p2'])
    expect(ids(filterColonies(colonies, 'P3', needs, NOW))).toEqual(['refill-soon-p3'])
    expect(ids(filterColonies(colonies, 'P4', needs, NOW))).toEqual(['upgradable-p4', 'refill-far-p4'])
    expect(ids(filterColonies(colonies, 'restart', needs, NOW))).toEqual(['expired-p1', 'expiring-p2'])
    expect(ids(filterColonies(colonies, 'refill', needs, NOW))).toEqual(['refill-soon-p3', 'refill-late-p2'])
    expect(ids(filterColonies(colonies, 'storage_full', needs, NOW))).toEqual(['full-p1'])
    expect(ids(filterColonies(colonies, 'upgrade', needs, NOW))).toEqual(['upgradable-p4', 'full-p1'])
  })

  it('counts every chip, the refill chip being unknown without input needs', () => {
    expect(filterCounts(colonies, needs, NOW)).toEqual({
      all: 9,
      P1: 3,
      P2: 2,
      P3: 1,
      P4: 2,
      restart: 2,
      refill: 2,
      storage_full: 1,
      upgrade: 2,
    })
    expect(filterCounts(colonies, null, NOW)).toEqual({
      all: 9,
      P1: 3,
      P2: 2,
      P3: 1,
      P4: 2,
      restart: 2,
      refill: null,
      storage_full: 1,
      upgrade: 2,
    })
  })
})
