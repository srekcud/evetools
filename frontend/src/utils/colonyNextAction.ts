import type { Colony, PlanetaryInputNeeds } from '@/stores/planetary'

export const REFILL_HORIZON_HOURS = 24

const HOUR_MS = 3_600_000

export type NextActionType = 'restart' | 'refill' | 'storage_full'

export interface NextAction {
  type: NextActionType
  at: number
}

export type ColonySortMode = 'nextAction' | 'character' | 'system'

export type ColonyFilterId = 'all' | 'P1' | 'P2' | 'P3' | 'P4' | 'restart' | 'refill' | 'storage_full' | 'upgrade'

export const COLONY_FILTER_IDS: readonly ColonyFilterId[] = ['all', 'P1', 'P2', 'P3', 'P4', 'restart', 'refill', 'storage_full', 'upgrade']

function restartActionOf(colony: Colony): NextAction | null {
  if (colony.extractorCount === 0 || colony.nearestExpiry === null) return null
  return { type: 'restart', at: Date.parse(colony.nearestExpiry) }
}

function refillInstantOf(colony: Colony, inputNeeds: PlanetaryInputNeeds): number | null {
  const instants = inputNeeds.launchpads
    .filter(launchpad => launchpad.colonyId === colony.id)
    .flatMap(launchpad => launchpad.feeds)
    .flatMap(feed => (feed.emptyAt === null ? [] : [Date.parse(feed.emptyAt)]))
  return instants.length === 0 ? null : Math.min(...instants)
}

function refillActionOf(colony: Colony, inputNeeds: PlanetaryInputNeeds | null): NextAction | null {
  if (inputNeeds === null) return null
  const at = refillInstantOf(colony, inputNeeds)
  return at === null ? null : { type: 'refill', at }
}

function storageFullActionOf(colony: Colony): NextAction | null {
  if (colony.storageFull !== true) return null
  return { type: 'storage_full', at: Date.parse(colony.lastUpdate) }
}

export function nextActionOf(colony: Colony, inputNeeds: PlanetaryInputNeeds | null): NextAction | null {
  const actions = [restartActionOf(colony), refillActionOf(colony, inputNeeds), storageFullActionOf(colony)]
    .filter((action): action is NextAction => action !== null)
  if (actions.length === 0) return null
  return actions.reduce((nearest, action) => (action.at < nearest.at ? action : nearest))
}

export function compareNextActions(a: NextAction | null, b: NextAction | null): number {
  if (a === null || b === null) {
    if (a === b) return 0
    return a === null ? 1 : -1
  }
  return a.at - b.at
}

export function sortColonies(colonies: Colony[], mode: ColonySortMode, inputNeeds: PlanetaryInputNeeds | null): Colony[] {
  const indexed = colonies.map((colony, index) => ({ colony, index }))

  if (mode === 'nextAction') {
    const withAction = indexed.map(entry => ({ ...entry, action: nextActionOf(entry.colony, inputNeeds) }))
    withAction.sort((a, b) => compareNextActions(a.action, b.action) || a.index - b.index)
    return withAction.map(entry => entry.colony)
  }

  if (mode === 'system') {
    indexed.sort((a, b) => (a.colony.solarSystemName ?? '').localeCompare(b.colony.solarSystemName ?? '') || a.index - b.index)
  }

  return indexed.map(entry => entry.colony)
}

function isRefillDue(colony: Colony, inputNeeds: PlanetaryInputNeeds, now: number): boolean {
  const at = refillInstantOf(colony, inputNeeds)
  return at !== null && at <= now + REFILL_HORIZON_HOURS * HOUR_MS
}

function matchesFilter(colony: Colony, filter: ColonyFilterId, inputNeeds: PlanetaryInputNeeds | null, now: number): boolean {
  switch (filter) {
    case 'all':
      return true
    case 'restart':
      return colony.status === 'expired' || colony.status === 'expiring'
    case 'refill':
      return inputNeeds !== null && isRefillDue(colony, inputNeeds, now)
    case 'storage_full':
      return colony.storageFull === true
    case 'upgrade':
      return colony.commandCenterUpgradable === true
    default:
      return colony.tier === filter
  }
}

export function filterColonies(colonies: Colony[], filter: ColonyFilterId, inputNeeds: PlanetaryInputNeeds | null, now: number): Colony[] {
  return colonies.filter(colony => matchesFilter(colony, filter, inputNeeds, now))
}

export function filterCounts(colonies: Colony[], inputNeeds: PlanetaryInputNeeds | null, now: number): Record<ColonyFilterId, number | null> {
  const counts = {} as Record<ColonyFilterId, number | null>
  for (const filter of COLONY_FILTER_IDS) {
    counts[filter] = filter === 'refill' && inputNeeds === null ? null : filterColonies(colonies, filter, inputNeeds, now).length
  }
  return counts
}
