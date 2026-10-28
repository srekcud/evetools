import { ref, watch, type Ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { SearchResult } from '@/stores/industry/types'
import { TYPE_RESULT_LIMIT } from '@/utils/commandPaletteSearch'

export const TYPE_SEARCH_DEBOUNCE_MS = 250
export const TYPE_SEARCH_MINIMUM_LENGTH = 2

export type TypeSearchStatus = 'idle' | 'searching' | 'ready' | 'unavailable'

export function useCommandPaletteTypeSearch(query: Ref<string>, isEnabled: Ref<boolean>) {
  const status = ref<TypeSearchStatus>('idle')
  const results = ref<SearchResult[]>([])
  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  let searchGeneration = 0

  function cancelPendingSearch(): void {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    searchGeneration += 1
  }

  async function search(term: string, generation: number): Promise<void> {
    let outcomeStatus: TypeSearchStatus
    let outcomeResults: SearchResult[] = []
    try {
      const answer = await apiRequest<{ results: SearchResult[] }>(`/industry/search?q=${encodeURIComponent(term)}`)
      outcomeStatus = 'ready'
      outcomeResults = answer.results.slice(0, TYPE_RESULT_LIMIT)
    } catch {
      outcomeStatus = 'unavailable'
    }
    if (generation === searchGeneration) {
      results.value = outcomeResults
      status.value = outcomeStatus
    }
  }

  watch([query, isEnabled], ([typedQuery, enabled]) => {
    cancelPendingSearch()
    results.value = []
    const term = typedQuery.trim()
    if (!enabled || term.length < TYPE_SEARCH_MINIMUM_LENGTH) {
      status.value = 'idle'
      return
    }
    status.value = 'searching'
    const generation = searchGeneration
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void search(term, generation)
    }, TYPE_SEARCH_DEBOUNCE_MS)
  })

  return { status, results }
}
