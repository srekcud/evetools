import { defineStore } from 'pinia'
import { ref } from 'vue'

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

  async function fetchMine(): Promise<void> {}

  async function fetchAvailable(): Promise<void> {}

  async function fetchDetail(_id: string): Promise<void> {}

  async function fetchMembers(_id: string): Promise<void> {}

  async function create(_input: CreateMiningOperationInput): Promise<MiningOperation | null> {
    return null
  }

  async function fetchInvitation(_code: string): Promise<void> {}

  async function join(_code: string, _characterIds: string[]): Promise<MiningOperationInvitation | null> {
    return null
  }

  async function renewJoinCode(_id: string): Promise<MiningOperation | null> {
    return null
  }

  async function leave(_id: string): Promise<void> {}

  async function deleteOperation(_id: string): Promise<void> {}

  async function updateMember(_operationId: string, _memberId: string, _input: UpdateMiningOperationMemberInput): Promise<MiningOperationMember | null> {
    return null
  }

  async function removeMember(_operationId: string, _memberId: string): Promise<void> {}

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
