import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectReferenceRow, ProjectReferences } from './types'

export const useGroupProjectReferencesStore = defineStore('group-project-references', () => {
  const rows = ref<ProjectReferenceRow[]>([])
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function fetchReferences(): Promise<void> {
    const request = ++latestRequest
    error.value = null
    try {
      const references = await apiRequest<ProjectReferences>('/industry/project-references')
      if (request === latestRequest) {
        rows.value = references.rows
      }
    } catch (e) {
      if (request === latestRequest) {
        rows.value = []
        error.value = e instanceof Error ? e.message : 'Failed to read the project references'
      }
    }
  }

  function referenceOf(productTypeId: number): ProjectReferenceRow | undefined {
    return rows.value.find(row => row.productTypeId === productTypeId)
  }

  return { rows, error, fetchReferences, referenceOf }
})
