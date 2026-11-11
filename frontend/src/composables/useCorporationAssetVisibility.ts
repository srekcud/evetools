import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { safeJsonParse } from '@/services/api'
import type CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import type { CorpAssetVisibility } from '@/types'

const VISIBILITY_URL = '/api/me/corporation/assets/visibility'

export interface CorporationVisibilityPayload {
  visibleDivisions: number[]
  restrictedDivisions: Record<number, string[]>
  shareBlueprintsOfHiddenDivisions: boolean
}

export function useCorporationAssetVisibility() {
  const authStore = useAuthStore()
  const visibility = ref<CorpAssetVisibility | null>(null)
  const loadFailed = ref(false)
  const saveError = ref('')
  const visibilityPanel = ref<InstanceType<typeof CorpVisibilityPanel> | null>(null)

  const isDirector = computed(() => visibility.value?.isDirector === true)

  async function fetchVisibility(): Promise<void> {
    try {
      const response = await fetch(VISIBILITY_URL, {
        headers: { 'Authorization': `Bearer ${authStore.token}` },
      })
      if (!response.ok) throw new Error('Failed to fetch visibility')
      visibility.value = await safeJsonParse<CorpAssetVisibility>(response)
      loadFailed.value = false
    } catch (e) {
      console.error('Failed to load corp visibility:', e)
      visibility.value = null
      loadFailed.value = true
    }
  }

  async function saveVisibility(payload: CorporationVisibilityPayload): Promise<boolean> {
    try {
      const response = await fetch(VISIBILITY_URL, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${authStore.token}`,
          'Content-Type': 'application/ld+json',
        },
        body: JSON.stringify(payload),
      })
      if (!response.ok) throw new Error('Failed to save visibility')
      saveError.value = ''
      visibilityPanel.value?.onSaveComplete()
      await fetchVisibility()
      return true
    } catch (e) {
      visibilityPanel.value?.onSaveError()
      saveError.value = e instanceof Error ? e.message : 'Failed to save visibility'
      return false
    }
  }

  function clearVisibility(): void {
    visibility.value = null
    loadFailed.value = false
  }

  return { visibility, visibilityPanel, isDirector, loadFailed, saveError, fetchVisibility, saveVisibility, clearVisibility }
}
