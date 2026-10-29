import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BlacklistConfig, SearchResult } from './types'

export const useBlacklistStore = defineStore('industry-blacklist', () => {
  const blacklist = ref<BlacklistConfig | null>(null)

  async function fetchBlacklist() {
    blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist')
  }

  async function updateBlacklist(groupIds: number[], typeIds: number[]) {
    blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist', {
      method: 'PUT',
      body: JSON.stringify({ groupIds, typeIds }),
    })
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
    fetchBlacklist,
    updateBlacklist,
    searchBlacklistItems,
  }
})
