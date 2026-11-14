import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { PackagedVolumesAnswer } from '@/types'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'

const PACKAGED_VOLUMES_ENDPOINT = '/me/assets/packaged-volumes'

export function usePackagedVolumes() {
  const catalog = ref<PackagedVolumeCatalog | null>(null)
  const unavailable = ref(false)

  async function fetchPackagedVolumes(): Promise<void> {
    try {
      const answer = await apiRequest<PackagedVolumesAnswer>(PACKAGED_VOLUMES_ENDPOINT)
      if (answer === null || typeof answer.volumes !== 'object' || !Array.isArray(answer.excludedTypeIds)) {
        throw new Error('Unexpected packaged volumes answer')
      }
      const entries = Object.entries(answer.volumes).map(([typeId, volume]): [number, number] => [Number(typeId), volume])
      catalog.value = entries.length === 0
        ? null
        : { volumes: new Map(entries), excludedTypeIds: new Set(answer.excludedTypeIds) }
      unavailable.value = entries.length === 0
    } catch (e) {
      console.error('Failed to load packaged volumes:', e)
      catalog.value = null
      unavailable.value = true
    }
  }

  return { catalog, unavailable, fetchPackagedVolumes }
}
