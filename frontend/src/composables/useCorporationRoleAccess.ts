import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export type CorporationRoleFeature = 'moon-tax' | 'extractions'

export type CorporationRoleAccessStatus = 'held' | 'role_missing' | 'reconnect' | 'esi_error'

export type CorporationRoleAccessCharacterState = 'without_role' | 'reconnect' | 'unreadable' | 'unknown'

export interface CorporationRoleAccessCharacter {
  characterId: number
  name: string
  isMain: boolean
  state: CorporationRoleAccessCharacterState
  roles: string[] | null
}

export interface CorporationRoleAccess {
  corporationId: number
  status: CorporationRoleAccessStatus
  requiredRole: string
  characters: CorporationRoleAccessCharacter[]
}

const ROLE_ACCESS_ENDPOINT = '/mining/corporation-role-access'

function isAccessPayload(payload: unknown): payload is CorporationRoleAccess {
  if (typeof payload !== 'object' || payload === null) return false
  const candidate = payload as Partial<CorporationRoleAccess>
  return typeof candidate.corporationId === 'number'
    && typeof candidate.status === 'string'
    && typeof candidate.requiredRole === 'string'
    && Array.isArray(candidate.characters)
}

export function isBlocked(access: CorporationRoleAccess | undefined): access is CorporationRoleAccess {
  return access !== undefined && access.status !== 'held'
}

export function useCorporationRoleAccess(feature: CorporationRoleFeature) {
  const accesses = ref<Record<number, CorporationRoleAccess>>({})
  const recheckingIds = ref<number[]>([])

  function endpointOf(corporationId: number, suffix = ''): string {
    return `${ROLE_ACCESS_ENDPOINT}/${corporationId}${suffix}?feature=${feature}`
  }

  function store(access: CorporationRoleAccess): void {
    accesses.value = { ...accesses.value, [access.corporationId]: access }
  }

  async function load(corporationId: number): Promise<void> {
    try {
      const payload = await apiRequest<unknown>(endpointOf(corporationId))
      if (!isAccessPayload(payload)) throw new Error('Unexpected corporation role access payload')
      store(payload)
    } catch (error) {
      console.error('Failed to read the corporation role access:', error)
    }
  }

  async function loadAll(corporationIds: number[]): Promise<void> {
    await Promise.all(corporationIds.map(load))
  }

  async function recheck(corporationId: number): Promise<CorporationRoleAccess | null> {
    recheckingIds.value = [...recheckingIds.value, corporationId]
    try {
      const payload = await apiRequest<unknown>(endpointOf(corporationId, '/recheck'), { method: 'POST', body: JSON.stringify({}) })
      if (!isAccessPayload(payload)) throw new Error('Unexpected corporation role access payload')
      store(payload)
      return payload
    } catch (error) {
      console.error('Failed to recheck the corporation role access:', error)
      return null
    } finally {
      recheckingIds.value = recheckingIds.value.filter(id => id !== corporationId)
    }
  }

  return { accesses, recheckingIds, loadAll, recheck }
}
