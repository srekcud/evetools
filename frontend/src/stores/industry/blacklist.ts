import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BlacklistConfig, SearchResult } from './types'

export const useBlacklistStore = defineStore('industry-blacklist', () => {
  const blacklist = ref<BlacklistConfig | null>(null)
  const error = ref<string | null>(null)

  async function fetchBlacklist() {
    try {
      blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch blacklist'
    }
  }

  async function updateBlacklist(groupIds: number[], typeIds: number[]) {
    try {
      blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist', {
        method: 'PUT',
        body: JSON.stringify({ groupIds, typeIds }),
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update blacklist'
    }
  }

  async function searchBlacklistItems(query: string): Promise<SearchResult[]> {
    if (query.length < 2) return []
    try {
      const data = await apiRequest<{ results: SearchResult[] }>(
        `/industry/blacklist/search?q=${encodeURIComponent(query)}`,
      )
      return data.results
    } catch (e) {
      return []
    }
  }

  return {
    blacklist,
    error,
    fetchBlacklist,
    updateBlacklist,
    searchBlacklistItems,
  }
})
