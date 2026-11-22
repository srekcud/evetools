import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { AttachLineBlueprintInput, LineBlueprintCandidate, ProjectLineBlueprint } from './types'

function lineBlueprintsPath(projectId: string): string {
  return `/industry/projects/${projectId}/line-blueprints`
}

function candidatesPath(projectId: string): string {
  return `/industry/projects/${projectId}/line-blueprint-candidates`
}

export const useGroupLineBlueprintsStore = defineStore('group-line-blueprints', () => {
  const blueprints = ref<ProjectLineBlueprint[]>([])
  const candidates = ref<LineBlueprintCandidate[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestBlueprintsRequest = 0
  let latestCandidatesRequest = 0

  function openProject(projectId: string): void {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      blueprints.value = []
      candidates.value = []
    }
  }

  async function fetchLineBlueprints(projectId: string): Promise<void> {
    openProject(projectId)
    const request = ++latestBlueprintsRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<ProjectLineBlueprint[]>(lineBlueprintsPath(projectId))
      if (request === latestBlueprintsRequest) {
        blueprints.value = read
      }
    } catch (e) {
      if (request === latestBlueprintsRequest) {
        blueprints.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch step blueprints'
      }
    } finally {
      if (request === latestBlueprintsRequest) {
        loading.value = false
      }
    }
  }

  async function fetchLineBlueprintCandidates(projectId: string): Promise<void> {
    openProject(projectId)
    const request = ++latestCandidatesRequest
    error.value = null
    try {
      const read = await apiRequest<LineBlueprintCandidate[]>(candidatesPath(projectId))
      if (request === latestCandidatesRequest) {
        candidates.value = read
      }
    } catch (e) {
      if (request === latestCandidatesRequest) {
        candidates.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch step blueprint candidates'
      }
    }
  }

  async function attachLineBlueprint(projectId: string, input: AttachLineBlueprintInput): Promise<void> {
    await apiRequest<ProjectLineBlueprint>(lineBlueprintsPath(projectId), {
      method: 'POST',
      body: JSON.stringify(input),
    })
    await fetchLineBlueprints(projectId)
  }

  async function detachLineBlueprint(projectId: string, lineBlueprintId: string): Promise<void> {
    await apiRequest<void>(`${lineBlueprintsPath(projectId)}/${lineBlueprintId}`, { method: 'DELETE' })
    await fetchLineBlueprints(projectId)
  }

  return { blueprints, candidates, loading, error, fetchLineBlueprints, fetchLineBlueprintCandidates, attachLineBlueprint, detachLineBlueprint }
})
