import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ChainNode, ChainStepMarkState, ProjectChain } from './types'

const NULLABLE_NODE_FIELDS = [
  'parentId',
  'level',
  'typeId',
  'typeName',
  'activity',
  'runs',
  'meLevel',
  'teLevel',
  'requiredQuantity',
  'fulfilledQuantity',
  'realCost',
  'plannedStructure',
  'plannedCopyDuration',
  'soldQuantity',
  'grossRevenue',
  'mark',
] as const satisfies readonly (keyof ChainNode)[]

function withExplicitNulls(node: ChainNode): ChainNode {
  const completed: ChainNode = { ...node }
  for (const field of NULLABLE_NODE_FIELDS) {
    if (completed[field] === undefined) Object.assign(completed, { [field]: null })
  }
  return completed
}

export const useGroupChainStore = defineStore('group-chain', () => {
  const nodes = ref<ChainNode[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchChain(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      nodes.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const chain = await apiRequest<ProjectChain>(`/industry/projects/${projectId}/chain`)
      if (request === latestRequest) {
        nodes.value = chain.nodes.map(withExplicitNulls)
      }
    } catch (e) {
      if (request === latestRequest) {
        nodes.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch production chain'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  const markError = ref<string | null>(null)

  async function changeMark(projectId: string, lineId: string, request: RequestInit, fallbackMessage: string): Promise<boolean> {
    markError.value = null
    try {
      await apiRequest(`/industry/projects/${projectId}/bom/${lineId}/mark`, request)
    } catch (failure) {
      markError.value = failure instanceof Error ? failure.message : fallbackMessage
      return false
    }
    await fetchChain(projectId)
    return true
  }

  function markStep(projectId: string, lineId: string, state: ChainStepMarkState, runs: number): Promise<boolean> {
    return changeMark(projectId, lineId, { method: 'POST', body: JSON.stringify({ state, runs }) }, 'Failed to mark the step')
  }

  function unmarkStep(projectId: string, lineId: string): Promise<boolean> {
    return changeMark(projectId, lineId, { method: 'DELETE' }, 'Failed to remove the mark')
  }

  return { nodes, loading, error, markError, fetchChain, markStep, unmarkStep }
})
