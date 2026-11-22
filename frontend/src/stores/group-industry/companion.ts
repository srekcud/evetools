import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useSyncStore } from '@/stores/sync'
import { projectDisplayNameOf } from '@/components/group-industry/projectDisplayName'
import { useUnassignedJobsStore } from './unassignedJobs'
import type { BomItem, CharacterLocationRead, ChainNode, GroupMember, GroupProject, IntermediateStockProposal, PlacedAction, ProjectChain, ProjectCockpit, ProjectPriceAlert, ProjectSellOrder, ProjectStationTransfer, ProjectTransportLegs } from './types'

const MEMBER_EVENT_PREFIX = 'member_'
const RECONNECTION_REFRESH = 'reconnected'
const CHARACTER_ORDERS_SYNC = 'character-orders'
const TRANSFER_EVENT = 'station_transfer_changed'
const MATERIAL_CONTRIBUTION = 'material'
const COMPLETED_PROJECT_STATUS = 'completed'

export type ProjectChoice = { id: string; name: string }

export const useGroupCompanionStore = defineStore('group-companion', () => {
  const projectId = ref<string | null>(null)
  const bomItems = ref<BomItem[]>([])
  const chainNodes = ref<ChainNode[] | null>(null)
  const project = ref<GroupProject | null>(null)
  const members = ref<GroupMember[]>([])
  const priceAlert = ref<ProjectPriceAlert | null>(null)
  const undercutOrders = ref<ProjectSellOrder[]>([])
  const placedActions = ref<PlacedAction[] | null>(null)
  const positions = ref<CharacterLocationRead[] | null>(null)
  const transfers = ref<ProjectStationTransfer[] | null>(null)
  const stockProposal = ref<IntermediateStockProposal | null>(null)
  const transportLegs = ref<ProjectTransportLegs | null>(null)
  const myProjects = ref<GroupProject[] | null>(null)
  const projectChoices = computed<ProjectChoice[]>(() => (myProjects.value ?? [])
    .filter(candidate => candidate.status !== COMPLETED_PROJECT_STATUS)
    .map(candidate => ({ id: candidate.id, name: projectDisplayNameOf(candidate) })))
  const error = ref<string | null>(null)
  const unassignedJobs = useUnassignedJobsStore()

  const isAdmin = computed(() => project.value?.myRole === 'owner' || project.value?.myRole === 'admin')

  function isStillOpen(id: string): boolean {
    return projectId.value === id
  }

  function reportFailure(failure: unknown, fallbackMessage: string): void {
    error.value = failure instanceof Error ? failure.message : fallbackMessage
  }

  async function loadProject(id: string): Promise<void> {
    try {
      const loaded = await apiRequest<GroupProject>(`/industry/projects/${id}`)
      if (!isStillOpen(id)) return
      project.value = loaded
      if (loaded.status === 'completed') close()
    } catch (failure) {
      if (isStillOpen(id)) reportFailure(failure, 'Failed to fetch project')
    }
  }

  async function loadBom(id: string): Promise<void> {
    try {
      const loaded = await apiRequest<BomItem[]>(`/industry/projects/${id}/bom`)
      if (isStillOpen(id)) bomItems.value = loaded
    } catch (failure) {
      if (isStillOpen(id)) reportFailure(failure, 'Failed to fetch BOM')
    }
  }

  async function loadChain(id: string): Promise<void> {
    try {
      const loaded = await apiRequest<ProjectChain>(`/industry/projects/${id}/chain`)
      if (isStillOpen(id)) chainNodes.value = loaded.nodes
    } catch (failure) {
      if (isStillOpen(id)) {
        chainNodes.value = null
        reportFailure(failure, 'Failed to fetch production chain')
      }
    }
  }

  async function loadCockpitAlerts(id: string): Promise<void> {
    try {
      const cockpit = await apiRequest<ProjectCockpit>(`/industry/projects/${id}/cockpit`)
      if (isStillOpen(id)) {
        priceAlert.value = cockpit.priceAlert
        undercutOrders.value = cockpit.undercutOrders
      }
    } catch (failure) {
      if (isStillOpen(id)) reportFailure(failure, 'Failed to fetch cockpit')
    }
  }

  async function readFlow<Value>(id: string, path: string, assign: (value: Value | null) => void, fallbackMessage: string): Promise<void> {
    try {
      const loaded = await apiRequest<Value>(path)
      if (isStillOpen(id)) assign(loaded)
    } catch (failure) {
      if (isStillOpen(id)) {
        assign(null)
        reportFailure(failure, fallbackMessage)
      }
    }
  }

  function loadPlacedActions(id: string): Promise<void> {
    return readFlow<PlacedAction[]>(id, `/industry/projects/${id}/place-actions`, (value) => { placedActions.value = value }, 'Failed to fetch the actions')
  }

  function loadPositions(id: string): Promise<void> {
    return readFlow<CharacterLocationRead[]>(id, '/me/characters/locations', (value) => { positions.value = value }, 'Failed to fetch the character positions')
  }

  function loadTransfers(id: string): Promise<void> {
    return readFlow<ProjectStationTransfer[]>(id, `/industry/projects/${id}/station-transfers`, (value) => { transfers.value = value }, 'Failed to fetch the station transfers')
  }

  function loadStockProposal(id: string): Promise<void> {
    if (project.value?.myStatus !== 'accepted') return Promise.resolve()
    return readFlow<IntermediateStockProposal>(id, `/industry/projects/${id}/intermediate-stock-proposal`, (value) => { stockProposal.value = value }, 'Failed to fetch the stock proposal')
  }

  function loadMyProjects(id: string): Promise<void> {
    return readFlow<GroupProject[]>(id, '/industry/projects', (value) => { myProjects.value = value }, 'Failed to fetch projects')
  }

  function resetFlow(): void {
    placedActions.value = null
    positions.value = null
    transfers.value = null
    stockProposal.value = null
  }

  async function loadMembers(id: string): Promise<void> {
    try {
      const loaded = await apiRequest<GroupMember[]>(`/industry/projects/${id}/members`)
      if (isStillOpen(id)) members.value = loaded
    } catch (failure) {
      if (isStillOpen(id)) reportFailure(failure, 'Failed to fetch members')
    }
  }

  async function open(id: string): Promise<void> {
    projectId.value = id
    bomItems.value = []
    chainNodes.value = null
    project.value = null
    members.value = []
    priceAlert.value = null
    undercutOrders.value = []
    resetFlow()
    error.value = null
    const projectLoad = loadProject(id)
    await Promise.all([
      projectLoad,
      projectLoad.then(() => loadStockProposal(id)),
      loadBom(id),
      loadChain(id),
      loadCockpitAlerts(id),
      unassignedJobs.load(),
      loadPlacedActions(id),
      loadPositions(id),
      loadTransfers(id),
      loadMyProjects(id),
    ])
    if (isStillOpen(id) && isAdmin.value) await loadMembers(id)
  }

  function close(): void {
    projectId.value = null
    bomItems.value = []
    chainNodes.value = null
    project.value = null
    members.value = []
    priceAlert.value = null
    undercutOrders.value = []
    resetFlow()
    myProjects.value = null
    error.value = null
  }

  async function changeReservation(lineId: string, request: RequestInit, fallbackMessage: string): Promise<void> {
    const id = projectId.value
    if (id === null) return
    error.value = null
    try {
      await apiRequest(`/industry/projects/${id}/bom/${lineId}/reservation`, request)
    } catch (failure) {
      reportFailure(failure, fallbackMessage)
    }
    await loadBom(id)
  }

  async function claim(lineId: string): Promise<void> {
    const line = bomItems.value.find(item => item.id === lineId)
    if (line === undefined) {
      error.value = 'Unknown BOM line'
      return
    }
    await changeReservation(
      lineId,
      { method: 'POST', body: JSON.stringify({ quantity: line.freeQuantity }) },
      'Failed to reserve the line',
    )
  }

  async function release(lineId: string): Promise<void> {
    await changeReservation(lineId, { method: 'DELETE' }, 'Failed to release the line')
  }

  async function refreshPlace(): Promise<void> {
    const id = projectId.value
    if (id === null) return
    await Promise.all([loadPositions(id), loadPlacedActions(id)])
  }

  async function arriveTransfer(transferId: string): Promise<void> {
    const id = projectId.value
    if (id === null) return
    error.value = null
    try {
      await apiRequest(`/industry/projects/${id}/station-transfers/${transferId}/arrive`, { method: 'POST', body: '{}' })
    } catch (failure) {
      reportFailure(failure, 'Failed to declare the arrival')
      return
    }
    await loadTransfers(id)
  }

  async function declareLeg(_key: string): Promise<void> {}

  async function supply(lineId: string): Promise<void> {
    const id = projectId.value
    if (id === null) return
    const line = stockProposal.value?.lines.find(candidate => candidate.bomItemId === lineId)
    if (line === undefined) {
      error.value = 'Unknown stock proposal line'
      return
    }
    error.value = null
    try {
      await apiRequest(`/industry/projects/${id}/contributions`, {
        method: 'POST',
        body: JSON.stringify({ bomItemId: lineId, type: MATERIAL_CONTRIBUTION, quantity: line.proposedQuantity }),
      })
    } catch (failure) {
      reportFailure(failure, 'Failed to supply the line')
    }
    await Promise.all([loadBom(id), loadStockProposal(id)])
  }

  async function switchProject(id: string): Promise<void> {
    if (id === projectId.value || !projectChoices.value.some(choice => choice.id === id)) return
    await open(id)
  }

  async function handleEvents(actions: string[]): Promise<void> {
    const id = projectId.value
    if (id === null) return
    const loads = [loadProject(id), loadBom(id), loadChain(id), loadCockpitAlerts(id), unassignedJobs.load(), loadPlacedActions(id), loadStockProposal(id)]
    if (actions.some(action => action === TRANSFER_EVENT || action === RECONNECTION_REFRESH)) loads.push(loadTransfers(id))
    if (actions.includes(RECONNECTION_REFRESH)) loads.push(loadPositions(id), loadMyProjects(id))
    if (actions.some(action => action.startsWith(MEMBER_EVENT_PREFIX)) && isAdmin.value) loads.push(loadMembers(id))
    await Promise.all(loads)
  }

  function handleEvent(action: string): Promise<void> {
    return handleEvents([action])
  }

  const syncStore = useSyncStore()
  watch(() => syncStore.getSyncProgress(CHARACTER_ORDERS_SYNC), (progress) => {
    const id = projectId.value
    if (id !== null && progress?.status === 'completed') void loadCockpitAlerts(id)
  })

  useRefetchOnReconnect(() => handleEvent(RECONNECTION_REFRESH))

  return { projectId, bomItems, chainNodes, project, members, priceAlert, undercutOrders, placedActions, positions, transfers, stockProposal, transportLegs, myProjects, projectChoices, error, open, close, claim, release, refreshPlace, arriveTransfer, declareLeg, supply, switchProject, handleEvent, handleEvents }
})
