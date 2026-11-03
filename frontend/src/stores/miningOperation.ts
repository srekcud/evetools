import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export type MiningOperationStatus = 'planned' | 'in_progress' | 'closing' | 'completed'
export type MiningOperationVisibility = 'private' | 'corporation'
export type MiningSplitMode = 'value_prorata' | 'each_for_themselves'
export type MiningOperationMemberRole = 'owner' | 'admin' | 'member'
export type MiningOperationMemberStatus = 'pending' | 'accepted' | 'removed'
export type MiningCharacterEligibility = 'eligible' | 'reconnect_required' | 'in_another_operation'

export interface MiningOperation {
  id: string
  label: string
  plannedStartAt: string
  status: MiningOperationStatus
  visibility: MiningOperationVisibility
  splitMode: MiningSplitMode
  corporationId: number | null
  ownerCharacterName: string | null
  participantsCount: number
  charactersCount: number
  joinCode: string | null
  myRole: MiningOperationMemberRole | null
  myStatus: MiningOperationMemberStatus | null
  createdAt: string
}

export interface MiningOperationMemberCharacter {
  id: string
  eveCharacterId: number
  name: string
}

export interface MiningOperationMember {
  id: string
  characterName: string | null
  corporationId: number | null
  role: MiningOperationMemberRole
  status: MiningOperationMemberStatus
  joinedAt: string
  characters: MiningOperationMemberCharacter[]
}

export interface MiningOperationInvitationCharacter {
  id: string
  eveCharacterId: number
  name: string
  isMain: boolean
  eligibility: MiningCharacterEligibility
  busyOperationLabel: string | null
}

export interface MiningOperationInvitation {
  code: string
  operationId: string
  label: string
  ownerCharacterName: string | null
  plannedStartAt: string
  status: MiningOperationStatus
  visibility: MiningOperationVisibility
  splitMode: MiningSplitMode
  participantsCount: number
  autoAccepted: boolean
  myRole: MiningOperationMemberRole | null
  myStatus: MiningOperationMemberStatus | null
  characters: MiningOperationInvitationCharacter[]
}

export interface CreateMiningOperationInput {
  label: string
  plannedStartAt: string
  visibility: MiningOperationVisibility
  splitMode: MiningSplitMode
  characterIds: string[]
}

export interface UpdateMiningOperationMemberInput {
  role?: 'admin' | 'member'
  status?: 'accepted'
}

export const useMiningOperationStore = defineStore('miningOperation', () => {
  const myOperations = ref<MiningOperation[]>([])
  const availableOperations = ref<MiningOperation[]>([])
  const currentOperation = ref<MiningOperation | null>(null)
  const members = ref<MiningOperationMember[]>([])
  const invitation = ref<MiningOperationInvitation | null>(null)
  const error = ref<string | null>(null)

  function messageOf(cause: unknown, fallback: string): string {
    return cause instanceof Error ? cause.message : fallback
  }

  async function fetchMine(): Promise<void> {
    error.value = null
    try {
      myOperations.value = await apiRequest<MiningOperation[]>('/mining-operations')
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch mining operations')
    }
  }

  async function fetchAvailable(): Promise<void> {
    error.value = null
    try {
      availableOperations.value = await apiRequest<MiningOperation[]>('/mining-operations/available')
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch available mining operations')
    }
  }

  async function fetchDetail(id: string): Promise<void> {
    error.value = null
    try {
      currentOperation.value = await apiRequest<MiningOperation>(`/mining-operations/${id}`)
    } catch (cause) {
      currentOperation.value = null
      error.value = messageOf(cause, 'Failed to fetch the mining operation')
    }
  }

  async function fetchMembers(id: string): Promise<void> {
    error.value = null
    try {
      members.value = await apiRequest<MiningOperationMember[]>(`/mining-operations/${id}/members`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the members')
    }
  }

  async function create(input: CreateMiningOperationInput): Promise<MiningOperation | null> {
    error.value = null
    try {
      const created = await apiRequest<MiningOperation>('/mining-operations', {
        method: 'POST',
        body: JSON.stringify(input),
      })
      myOperations.value = [created, ...myOperations.value]
      return created
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to create the mining operation')
      throw cause
    }
  }

  async function fetchInvitation(code: string): Promise<void> {
    error.value = null
    invitation.value = null
    try {
      invitation.value = await apiRequest<MiningOperationInvitation>(`/mining-operations/join/${code}`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the invitation')
      throw cause
    }
  }

  async function join(code: string, characterIds: string[]): Promise<MiningOperationInvitation | null> {
    error.value = null
    try {
      invitation.value = await apiRequest<MiningOperationInvitation>(`/mining-operations/join/${code}`, {
        method: 'POST',
        body: JSON.stringify({ characterIds }),
      })
      return invitation.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to join the mining operation')
      throw cause
    }
  }

  async function renewJoinCode(id: string): Promise<MiningOperation | null> {
    error.value = null
    try {
      currentOperation.value = await apiRequest<MiningOperation>(`/mining-operations/${id}/join-code`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      return currentOperation.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to renew the join code')
      throw cause
    }
  }

  async function leave(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/${id}/leave`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      myOperations.value = myOperations.value.filter(operation => operation.id !== id)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to leave the mining operation')
      throw cause
    }
  }

  async function deleteOperation(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/${id}`, { method: 'DELETE' })
      myOperations.value = myOperations.value.filter(operation => operation.id !== id)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to delete the mining operation')
      throw cause
    }
  }

  async function updateMember(operationId: string, memberId: string, input: UpdateMiningOperationMemberInput): Promise<MiningOperationMember | null> {
    error.value = null
    try {
      const updated = await apiRequest<MiningOperationMember>(`/mining-operations/${operationId}/members/${memberId}`, {
        method: 'PATCH',
        body: JSON.stringify(input),
      })
      members.value = members.value.map(member => (member.id === memberId ? updated : member))
      return updated
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to update the member')
      throw cause
    }
  }

  async function removeMember(operationId: string, memberId: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/${operationId}/members/${memberId}`, { method: 'DELETE' })
      members.value = members.value.filter(member => member.id !== memberId)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to remove the member')
      throw cause
    }
  }

  return {
    myOperations,
    availableOperations,
    currentOperation,
    members,
    invitation,
    error,
    fetchMine,
    fetchAvailable,
    fetchDetail,
    fetchMembers,
    create,
    fetchInvitation,
    join,
    renewJoinCode,
    leave,
    deleteOperation,
    updateMember,
    removeMember,
  }
})
