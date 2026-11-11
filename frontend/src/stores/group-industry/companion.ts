import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import type { BomItem, GroupMember, GroupProject, ProjectCockpit, ProjectPriceAlert, ProjectSellOrder } from './types'

const MEMBER_EVENT_PREFIX = 'member_'
const CHARACTER_ORDERS_SYNC = 'character-orders'

export const useGroupCompanionStore = defineStore('group-companion', () => {
  const projectId = ref<string | null>(null)
  const bomItems = ref<BomItem[]>([])
  const project = ref<GroupProject | null>(null)
  const members = ref<GroupMember[]>([])
  const priceAlert = ref<ProjectPriceAlert | null>(null)
  const undercutOrders = ref<ProjectSellOrder[]>([])
  const error = ref<string | null>(null)

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
    project.value = null
    members.value = []
    priceAlert.value = null
    undercutOrders.value = []
    error.value = null
    await Promise.all([loadProject(id), loadBom(id), loadCockpitAlerts(id)])
    if (isStillOpen(id) && isAdmin.value) await loadMembers(id)
  }

  function close(): void {
    projectId.value = null
    bomItems.value = []
    project.value = null
    members.value = []
    priceAlert.value = null
    undercutOrders.value = []
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

  async function handleEvent(action: string): Promise<void> {
    const id = projectId.value
    if (id === null) return
    const loads = [loadProject(id), loadBom(id), loadCockpitAlerts(id)]
    if (action.startsWith(MEMBER_EVENT_PREFIX) && isAdmin.value) loads.push(loadMembers(id))
    await Promise.all(loads)
  }

  const syncStore = useSyncStore()
  watch(() => syncStore.getSyncProgress(CHARACTER_ORDERS_SYNC), (progress) => {
    const id = projectId.value
    if (id !== null && progress?.status === 'completed') void loadCockpitAlerts(id)
  })

  return { projectId, bomItems, project, members, priceAlert, undercutOrders, error, open, close, claim, release, handleEvent }
})
