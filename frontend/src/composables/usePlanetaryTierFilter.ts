import { ref } from 'vue'
import { usePlanetaryTiers } from '@/composables/usePlanetaryTiers'

export const PLANETARY_TIER_FILTERS = ['all', 'P1', 'P2', 'P3', 'P4'] as const

export type PlanetaryTierFilter = (typeof PLANETARY_TIER_FILTERS)[number]

export function usePlanetaryTierFilter() {
  const { tierOf } = usePlanetaryTiers()
  const selectedTier = ref<PlanetaryTierFilter>('all')

  function matchesTier(typeId: number): boolean {
    return selectedTier.value === 'all' || tierOf(typeId) === selectedTier.value
  }

  return { selectedTier, matchesTier }
}
