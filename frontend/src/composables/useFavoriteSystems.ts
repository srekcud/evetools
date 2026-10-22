import { computed, type ComputedRef } from 'vue'
import { useStructuresStore } from '@/stores/industry/structures'

export interface FavoriteSystem {
  id: number
  name: string
}

export function useFavoriteSystems(): ComputedRef<FavoriteSystem[]> {
  const structuresStore = useStructuresStore()

  return computed(() => {
    const settings = structuresStore.userSettings
    if (!settings) return []
    const systems: FavoriteSystem[] = []
    if (settings.favoriteManufacturingSystemId != null && settings.favoriteManufacturingSystemName != null) {
      systems.push({ id: settings.favoriteManufacturingSystemId, name: settings.favoriteManufacturingSystemName })
    }
    if (settings.favoriteReactionSystemId != null && settings.favoriteReactionSystemName != null) {
      if (!systems.find(s => s.id === settings.favoriteReactionSystemId)) {
        systems.push({ id: settings.favoriteReactionSystemId, name: settings.favoriteReactionSystemName })
      }
    }
    return systems
  })
}
