import type { Colony, PlanetaryInputNeeds } from '@/stores/planetary'

export const REFILL_HORIZON_HOURS = 24

export type NextActionType = 'restart' | 'refill' | 'storage_full'

export interface NextAction {
  type: NextActionType
  at: number
}

export type ColonySortMode = 'nextAction' | 'character' | 'system'

export type ColonyFilterId = 'all' | 'P1' | 'P2' | 'P3' | 'P4' | 'restart' | 'refill' | 'storage_full' | 'upgrade'

export const COLONY_FILTER_IDS: readonly ColonyFilterId[] = ['all', 'P1', 'P2', 'P3', 'P4', 'restart', 'refill', 'storage_full', 'upgrade']

export function nextActionOf(_colony: Colony, _inputNeeds: PlanetaryInputNeeds | null): NextAction | null {
  return null
}

export function sortColonies(colonies: Colony[], _mode: ColonySortMode, _inputNeeds: PlanetaryInputNeeds | null): Colony[] {
  return [...colonies]
}

export function filterColonies(colonies: Colony[], _filter: ColonyFilterId, _inputNeeds: PlanetaryInputNeeds | null, _now: number): Colony[] {
  return [...colonies]
}

export function filterCounts(_colonies: Colony[], _inputNeeds: PlanetaryInputNeeds | null, _now: number): Record<ColonyFilterId, number | null> {
  return { all: 0, P1: 0, P2: 0, P3: 0, P4: 0, restart: 0, refill: 0, storage_full: 0, upgrade: 0 }
}
