import { ref } from 'vue'
import { ApiError, apiRequest } from '@/services/api'

export interface ChunkMinerStatement {
  periodStart: string
  periodEnd: string
  reference: string | null
  status: string | null
  tax: number | null
  paid: number | null
  remaining: number | null
}

export interface ChunkMiner {
  characterId: number
  characterName: string | null
  chunkTax: number | null
  unknownTypeIds: number[]
  statements: ChunkMinerStatement[]
}

export interface ChunkMinersPayload {
  id: string
  taxBasis: string
  refineryOpen: boolean
  miners: ChunkMiner[]
}

export type ChunkMinersState =
  | { kind: 'loading' }
  | { kind: 'denied' }
  | { kind: 'error' }
  | { kind: 'loaded'; payload: ChunkMinersPayload }

const MOON_TAX_ENDPOINT = '/mining/moon-tax'
const HTTP_FORBIDDEN = 403

function isChunkMinersPayload(payload: unknown): payload is ChunkMinersPayload {
  if (typeof payload !== 'object' || payload === null) return false
  const candidate = payload as Partial<ChunkMinersPayload>
  return typeof candidate.refineryOpen === 'boolean' && Array.isArray(candidate.miners)
}

export function useMoonChunkMiners() {
  const states = ref<Record<string, ChunkMinersState>>({})

  function setState(chunkId: string, state: ChunkMinersState): void {
    states.value = { ...states.value, [chunkId]: state }
  }

  async function load(corporationId: number, chunkId: string): Promise<void> {
    const current = states.value[chunkId]
    if (current !== undefined && current.kind !== 'error') return
    setState(chunkId, { kind: 'loading' })
    try {
      const payload = await apiRequest<unknown>(`${MOON_TAX_ENDPOINT}/${corporationId}/chunks/${chunkId}/miners`)
      if (!isChunkMinersPayload(payload)) throw new Error('Unexpected moon chunk miners payload')
      setState(chunkId, { kind: 'loaded', payload })
    } catch (error) {
      setState(chunkId, { kind: error instanceof ApiError && error.status === HTTP_FORBIDDEN ? 'denied' : 'error' })
    }
  }

  return { states, load }
}
