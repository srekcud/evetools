import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ChainNode, ProjectChain } from './types'

const NULLABLE_NODE_FIELDS = [
  'parentId',
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
  'soldQuantity',
  'grossRevenue',
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

  return { nodes, loading, error, fetchChain }
})
