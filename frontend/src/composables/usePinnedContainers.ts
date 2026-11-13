import { computed, ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { PinnedContainer } from '@/types'

const PINNED_CONTAINERS_ENDPOINT = '/me/pinned-containers'

export function usePinnedContainers() {
  const entries = ref<PinnedContainer[]>([])
  const loadFailed = ref(false)

  const pinnedItemIds = computed(() => new Set(entries.value.flatMap(entry => (entry.itemId === null ? [] : [entry.itemId]))))
  const projectPinnedItemIds = computed(() => new Set(
    entries.value.flatMap(entry => (entry.itemId === null || entry.projectId === null ? [] : [entry.itemId])),
  ))

  async function fetchPinned(): Promise<void> {
    try {
      const answer = await apiRequest<PinnedContainer[]>(PINNED_CONTAINERS_ENDPOINT)
      if (!Array.isArray(answer)) {
        throw new Error('Unexpected pinned containers answer')
      }
      entries.value = answer
      loadFailed.value = false
    } catch (e) {
      console.error('Failed to load pinned containers:', e)
      entries.value = []
      loadFailed.value = true
    }
  }

  async function pin(itemId: number): Promise<void> {
    await apiRequest(PINNED_CONTAINERS_ENDPOINT, { method: 'POST', body: JSON.stringify({ itemId }) })
    await fetchPinned()
  }

  async function unpin(itemId: number): Promise<void> {
    await apiRequest(`${PINNED_CONTAINERS_ENDPOINT}/${itemId}`, { method: 'DELETE' })
    await fetchPinned()
  }

  function clearPinned(): void {
    entries.value = []
    loadFailed.value = false
  }

  return { entries, loadFailed, pinnedItemIds, projectPinnedItemIds, fetchPinned, pin, unpin, clearPinned }
}
