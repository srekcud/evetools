import { computed } from 'vue'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'

export function usePlanetaryTiers() {
  const planner = usePlanetaryPlannerStore()

  const tierByTypeId = computed(() => new Map((planner.catalog?.types ?? []).map(type => [type.typeId, type.tier])))

  function tierOf(typeId: number): string | null {
    return tierByTypeId.value.get(typeId) ?? null
  }

  function ensureCatalog(): void {
    if (planner.catalog === null && !planner.isLoading) void planner.fetchCatalog()
  }

  return { tierOf, ensureCatalog }
}
