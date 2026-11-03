import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export type MiningOperationStatus = 'planned' | 'in_progress' | 'closing' | 'shares' | 'settled' | 'completed'
export type MiningOperationVisibility = 'private' | 'corporation'
export type MiningSplitMode = 'value_prorata' | 'each_for_themselves'
export type MiningOperationMemberRole = 'owner' | 'admin' | 'member'
export type MiningOperationMemberStatus = 'pending' | 'accepted' | 'removed'
export type MiningCharacterEligibility = 'eligible' | 'reconnect_required' | 'in_another_operation'
export type MiningValuationForm = 'raw' | 'compressed' | 'reprocessed'
export type MiningValuationMarket = 'jita' | 'structure'
export type MiningValuationSide = 'buy' | 'sell'
export type MiningShareBlocker = 'not_closing' | 'each_for_themselves' | 'quantities_not_known' | 'prices_missing' | 'booster_missing' | 'fleet_storage_recipient_missing' | 'fleet_storage_exceeds_mined'

export interface MiningValuationSettings {
  valuationForm: MiningValuationForm
  valuationMarket: MiningValuationMarket
  valuationSide: MiningValuationSide
  structureId: number | null
  structureName: string | null
  reprocessYield: number | null
  boosterCutPercent: number
  corporationCutPercent: number
  boosterMemberId: string | null
  boosterCharacterName: string | null
}

export type MiningInvitationValuationSettings = Omit<MiningValuationSettings, 'boosterMemberId'>

export type UpdateMiningValuationInput = Partial<Omit<MiningValuationSettings, 'boosterCharacterName'>>

export interface MiningArchiveSummary {
  archivedAt: string
  archivedByName: string | null
  totalValue: number | null
  myMinedValue: number | null
  myShare: number | null
  paymentsCount: number
  paidPaymentsCount: number
}

export type MiningPaymentKind = 'booster_cut' | 'corporation_cut' | 'collected_corporation_cut' | 'fleet_storage_share'

export type MiningFleetStorageDelivery = 'none' | 'all' | 'partial'

export interface MiningFleetStorageQuantity {
  typeId: number
  typeName: string | null
  quantity: number
}

export interface MiningFleetStorageDeclaration {
  memberId: string
  characterName: string | null
  delivery: MiningFleetStorageDelivery
  quantities: MiningFleetStorageQuantity[]
  canDeclare: boolean
}

export interface MiningFleetStorage {
  operationId: string
  available: boolean
  editable: boolean
  recipientMemberId: string | null
  recipientName: string | null
  containerName: string | null
  declarations: MiningFleetStorageDeclaration[]
}

export interface UpdateMiningFleetStorageInput {
  recipientMemberId?: string | null
  containerName?: string | null
}

export interface DeclareMiningFleetStorageInput {
  delivery: MiningFleetStorageDelivery
  quantities?: { typeId: number; quantity: number }[]
}

export type MiningFleetStorageCheck = 'verified' | 'short' | 'unknown' | 'nothing_delivered'

export type MiningFleetStorageUnknownReason =
  | 'operation_not_ended'
  | 'operation_archived'
  | 'no_container_name'
  | 'no_corporation'
  | 'not_corporation_member'
  | 'corporation_assets_not_synced'
  | 'no_visible_division'
  | 'assets_synced_before_end'
  | 'container_not_found'
  | 'delivered_quantity_unknown'

export interface MiningFleetStorageVerificationLine {
  typeId: number
  typeName: string | null
  expectedQuantity: number
  foundQuantity: number | null
  missingQuantity: number | null
  status: Exclude<MiningFleetStorageCheck, 'nothing_delivered'>
}

export interface MiningFleetStorageVerification {
  operationId: string
  status: MiningFleetStorageCheck
  unknownReason: MiningFleetStorageUnknownReason | null
  containerName: string | null
  assetsReadAt: string | null
  lines: MiningFleetStorageVerificationLine[]
}

export interface MiningPayment {
  id: string
  position: number
  kind: MiningPaymentKind
  payerMemberId: string | null
  payerName: string | null
  beneficiaryMemberId: string | null
  beneficiaryName: string | null
  amount: number
  paid: boolean
  paidAt: string | null
  paidByName: string | null
}

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
  startedAt: string | null
  endedAt: string | null
  solarSystemId: number | null
  solarSystemName: string | null
  myDepartedAt: string | null
  valuationSettings: MiningValuationSettings
  sharesFrozenAt: string | null
  archivedAt: string | null
  archivedByName: string | null
  archiveSummary: MiningArchiveSummary | null
  fleetStorageRecipientName: string | null
  recurrenceId: string | null
  chunkId: string | null
}

export interface MiningOperationMemberCharacter {
  id: string
  eveCharacterId: number
  name: string
}

export type MiningPresenceState = 'online' | 'afk' | 'left' | 'unknown'

export interface MiningOperationMember {
  id: string
  characterName: string | null
  corporationId: number | null
  role: MiningOperationMemberRole
  status: MiningOperationMemberStatus
  joinedAt: string
  departedAt: string | null
  presence: MiningPresenceState | null
  presenceIdleMinutes: number | null
  characters: MiningOperationMemberCharacter[]
}

export type MiningLedgerSnapshotStatus = 'scheduling' | 'waiting' | 'taken' | 'reconnect_required' | 'failed' | 'out_of_window'
export type MinedQuantityState = 'measuring' | 'known' | 'unknown'
export type MinedQuantityUnknownReason = 'reconnect_required' | 'read_failed' | 'out_of_window' | 'ledger_inconsistent' | 'no_end_snapshot'

export interface MiningLedgerSnapshot {
  status: MiningLedgerSnapshotStatus
  referenceAt: string
  readyAt: string | null
  takenAt: string | null
}

export interface MiningQuantityCorrection {
  originalQuantity: number | null
  correctedQuantity: number
  correctedByName: string | null
  correctedAt: string
}

export interface MinedTypeQuantity {
  typeId: number
  typeName: string
  quantity: number
}

export interface MiningLedgerCharacterQuantity extends MinedTypeQuantity {
  measuredQuantity: number | null
  correction: MiningQuantityCorrection | null
}

export interface MiningLedgerCharacter {
  id: string
  eveCharacterId: number
  name: string
  state: MinedQuantityState
  unknownReason: MinedQuantityUnknownReason | null
  totalQuantity: number | null
  startSnapshot: MiningLedgerSnapshot | null
  endSnapshot: MiningLedgerSnapshot | null
  canRetry: boolean
  quantities: MiningLedgerCharacterQuantity[]
}

export interface MiningLedgerParticipant {
  memberId: string
  characterName: string | null
  departedAt: string | null
  state: MinedQuantityState
  totalQuantity: number | null
  quantities: MinedTypeQuantity[]
  characters: MiningLedgerCharacter[]
}

export interface MiningOperationLedger {
  operationId: string
  status: MiningOperationStatus
  startedAt: string | null
  endedAt: string | null
  solarSystemId: number | null
  solarSystemName: string | null
  endSnapshotsReadyAt: string | null
  endSnapshotsPending: boolean
  endSnapshotsSettled: number
  endSnapshotsTotal: number
  participants: MiningLedgerParticipant[]
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
  valuationSettings: MiningInvitationValuationSettings
  fleetStorageRecipientName: string | null
}

export interface MiningUnitPrice {
  typeId: number
  typeName: string | null
  unitPrice: number | null
}

export interface MiningValuationCharacter {
  id: string
  name: string
  quantityState: MinedQuantityState
  totalQuantity: number | null
  minedValue: number | null
}

export interface MiningValuationParticipant {
  memberId: string | null
  characterName: string | null
  isBooster: boolean
  quantityState: MinedQuantityState | null
  totalQuantity: number | null
  minedValue: number | null
  valuePercent: number | null
  share: number | null
  roundingAdjustment: number | null
  iskPerHour: number | null
  durationSeconds: number | null
  departedAt: string | null
  characters: MiningValuationCharacter[]
  fleetStorageDelivery: MiningFleetStorageDelivery | null
  isFleetStorageRecipient: boolean
  deliveredValue: number | null
  keptValue: number | null
  fleetStorageDebt: number | null
}

export interface MiningOperationValuation {
  operationId: string
  status: MiningOperationStatus
  splitMode: MiningSplitMode
  frozen: boolean
  sharesFrozenAt: string | null
  pricesReadAt: string | null
  pricesSyncedAt: string | null
  settings: MiningValuationSettings
  unitPrices: MiningUnitPrice[]
  missingPriceTypeIds: number[]
  totalValue: number | null
  boosterCut: number | null
  corporationCut: number | null
  iskPerHour: number | null
  durationSeconds: number | null
  roundingRemainder: number | null
  boosterRoundingAdjustment: number | null
  corporationRoundingAdjustment: number | null
  participants: MiningValuationParticipant[]
  canFreeze: boolean
  freezeBlockers: MiningShareBlocker[]
  canComplete: boolean
  completionBlockers: MiningShareBlocker[] | null
  fleetStorageRecipientMemberId: string | null
  fleetStorageRecipientName: string | null
}

export type MiningOperationRecurrenceKind = 'weekly' | 'chunk'

export type MiningOperationRecurrenceInput = { kind: 'weekly' } | { kind: 'chunk'; structureId: number }

export interface MiningOperationRecurrence {
  id: string
  kind: MiningOperationRecurrenceKind
  label: string
  structureId: number | null
  structureName: string | null
  nextOccurrenceAt: string | null
  createdAt: string
}

export interface CreateMiningOperationInput {
  label: string
  plannedStartAt: string
  visibility: MiningOperationVisibility
  splitMode: MiningSplitMode
  characterIds: string[]
  recurrence?: MiningOperationRecurrenceInput
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
  const ledger = ref<MiningOperationLedger | null>(null)
  const valuation = ref<MiningOperationValuation | null>(null)
  const payments = ref<MiningPayment[]>([])
  const archivedOperations = ref<MiningOperation[]>([])
  const recurrences = ref<MiningOperationRecurrence[]>([])
  const fleetStorage = ref<MiningFleetStorage | null>(null)
  const fleetStorageVerification = ref<MiningFleetStorageVerification | null>(null)
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
      if (input.recurrence !== undefined) await fetchRecurrences()
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

  async function fetchRecurrences(): Promise<void> {
    error.value = null
    try {
      recurrences.value = await apiRequest<MiningOperationRecurrence[]>('/mining-operations/recurrences')
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the mining operation recurrences')
    }
  }

  async function stopRecurrence(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/recurrences/${id}`, { method: 'DELETE' })
      recurrences.value = recurrences.value.filter(recurrence => recurrence.id !== id)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to stop the recurrence')
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

  async function postLifecycleAction(id: string, action: 'start' | 'end' | 'depart' | 'settle' | 'complete', body: object, failureMessage: string): Promise<MiningOperation | null> {
    error.value = null
    try {
      currentOperation.value = await apiRequest<MiningOperation>(`/mining-operations/${id}/${action}`, {
        method: 'POST',
        body: JSON.stringify(body),
      })
      return currentOperation.value
    } catch (cause) {
      error.value = messageOf(cause, failureMessage)
      throw cause
    }
  }

  const start = (id: string, solarSystemId: number | null) =>
    postLifecycleAction(id, 'start', { solarSystemId }, 'Failed to start the mining operation')

  const end = (id: string) => postLifecycleAction(id, 'end', {}, 'Failed to end the mining operation')

  const depart = (id: string) => postLifecycleAction(id, 'depart', {}, 'Failed to leave the mining operation early')

  async function recordPresence(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<MiningOperation>(`/mining-operations/${id}/presence`, { method: 'POST', body: JSON.stringify({}) })
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to record the presence')
    }
  }

  async function fetchLedger(id: string): Promise<void> {
    error.value = null
    try {
      ledger.value = await apiRequest<MiningOperationLedger>(`/mining-operations/${id}/ledger`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the ledger')
    }
  }

  async function retrySnapshot(operationId: string, operationCharacterId: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/${operationId}/characters/${operationCharacterId}/snapshot-retry`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to retry the snapshot')
      throw cause
    }
  }

  async function correctQuantity(operationId: string, operationCharacterId: string, typeId: number, quantity: number): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/mining-operations/${operationId}/characters/${operationCharacterId}/quantity-corrections`, {
        method: 'POST',
        body: JSON.stringify({ typeId, quantity }),
      })
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to correct the quantity')
      throw cause
    }
  }

  async function fetchValuation(id: string): Promise<void> {
    error.value = null
    try {
      valuation.value = await apiRequest<MiningOperationValuation>(`/mining-operations/${id}/valuation`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the valuation')
    }
  }

  async function updateValuation(id: string, patch: UpdateMiningValuationInput): Promise<MiningOperationValuation | null> {
    error.value = null
    try {
      valuation.value = await apiRequest<MiningOperationValuation>(`/mining-operations/${id}/valuation`, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
      return valuation.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to update the valuation settings')
      throw cause
    }
  }

  async function freezeShares(id: string): Promise<MiningOperation | null> {
    error.value = null
    try {
      currentOperation.value = await apiRequest<MiningOperation>(`/mining-operations/${id}/freeze-shares`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      return currentOperation.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to freeze the shares')
      throw cause
    }
  }

  async function fetchPayments(id: string): Promise<void> {
    error.value = null
    try {
      payments.value = await apiRequest<MiningPayment[]>(`/mining-operations/${id}/payments`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the payments')
    }
  }

  async function markPayment(operationId: string, paymentId: string, paid: boolean): Promise<MiningPayment | null> {
    error.value = null
    try {
      const marked = await apiRequest<MiningPayment>(`/mining-operations/${operationId}/payments/${paymentId}`, {
        method: 'PATCH',
        body: JSON.stringify({ paid }),
      })
      payments.value = payments.value.map(line => (line.id === paymentId ? marked : line))
      return marked
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to mark the payment')
      throw cause
    }
  }

  const settle = (id: string) => postLifecycleAction(id, 'settle', {}, 'Failed to settle the mining operation')

  const complete = (id: string) => postLifecycleAction(id, 'complete', {}, 'Failed to complete the mining operation')

  async function fetchArchive(): Promise<void> {
    error.value = null
    try {
      archivedOperations.value = await apiRequest<MiningOperation[]>('/mining-operations/archive')
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the archive')
    }
  }

  async function fetchFleetStorage(id: string): Promise<void> {
    error.value = null
    try {
      fleetStorage.value = await apiRequest<MiningFleetStorage>(`/mining-operations/${id}/fleet-storage`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the fleet storage')
    }
  }

  async function updateFleetStorage(id: string, patch: UpdateMiningFleetStorageInput): Promise<MiningFleetStorage | null> {
    error.value = null
    try {
      fleetStorage.value = await apiRequest<MiningFleetStorage>(`/mining-operations/${id}/fleet-storage`, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
      return fleetStorage.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to update the fleet storage')
      throw cause
    }
  }

  async function declareFleetStorage(id: string, memberId: string, declaration: DeclareMiningFleetStorageInput): Promise<MiningFleetStorage | null> {
    error.value = null
    try {
      fleetStorage.value = await apiRequest<MiningFleetStorage>(`/mining-operations/${id}/fleet-storage/declarations/${memberId}`, {
        method: 'PATCH',
        body: JSON.stringify(declaration),
      })
      return fleetStorage.value
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to declare the fleet storage')
      throw cause
    }
  }

  async function fetchFleetStorageVerification(id: string): Promise<void> {
    error.value = null
    try {
      fleetStorageVerification.value = await apiRequest<MiningFleetStorageVerification>(`/mining-operations/${id}/fleet-storage/verification`)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to check the fleet storage container')
    }
  }

  async function refreshCorporationAssets(): Promise<void> {
    error.value = null
    try {
      await apiRequest<unknown>('/me/corporation/assets/refresh', {
        method: 'POST',
        body: JSON.stringify({}),
      })
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to refresh the corporation assets')
      throw cause
    }
  }

  return {
    myOperations,
    availableOperations,
    currentOperation,
    members,
    invitation,
    ledger,
    valuation,
    payments,
    archivedOperations,
    fleetStorage,
    fleetStorageVerification,
    error,
    fetchMine,
    fetchAvailable,
    fetchDetail,
    fetchMembers,
    create,
    recurrences,
    fetchRecurrences,
    stopRecurrence,
    fetchInvitation,
    join,
    renewJoinCode,
    leave,
    deleteOperation,
    updateMember,
    removeMember,
    start,
    end,
    depart,
    recordPresence,
    fetchLedger,
    retrySnapshot,
    correctQuantity,
    fetchValuation,
    updateValuation,
    freezeShares,
    fetchPayments,
    markPayment,
    settle,
    complete,
    fetchArchive,
    fetchFleetStorage,
    updateFleetStorage,
    declareFleetStorage,
    fetchFleetStorageVerification,
    refreshCorporationAssets,
  }
})
