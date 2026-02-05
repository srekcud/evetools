import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

export interface Escalation {
  id: string
  characterId: number
  characterName: string
  type: string
  solarSystemId: number
  solarSystemName: string
  securityStatus: number
  price: number
  visibility: 'perso' | 'corp' | 'alliance' | 'public'
  bmStatus: 'nouveau' | 'bm'
  saleStatus: 'envente' | 'vendu'
  notes: string | null
  corporationId: number
  expiresAt: string
  createdAt: string
  updatedAt: string
  isOwner: boolean
}

export interface CreateEscalationInput {
  characterId: number
  type: string
  solarSystemId: number
  solarSystemName: string
  securityStatus: number
  price: number
  notes?: string | null
  timerHours?: number
  visibility?: 'perso' | 'corp' | 'alliance' | 'public'
}

export const useEscalationStore = defineStore('escalation', () => {
  // State
  const escalations = ref<Escalation[]>([])
  const corpEscalations = ref<Escalation[]>([])
  const publicEscalations = ref<Escalation[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  // Computed
  const activeEscalations = computed(() =>
    escalations.value.filter(e => e.saleStatus !== 'vendu' && new Date(e.expiresAt) > new Date())
  )

  const counts = computed(() => {
    const all = escalations.value
    return {
      total: all.length,
      nouveau: all.filter(e => e.bmStatus === 'nouveau').length,
      bm: all.filter(e => e.bmStatus === 'bm').length,
      envente: all.filter(e => e.saleStatus === 'envente').length,
      vendu: all.filter(e => e.saleStatus === 'vendu').length,
    }
  })

  const totalSoldValue = computed(() =>
    escalations.value
      .filter(e => e.saleStatus === 'vendu')
      .reduce((sum, e) => sum + e.price, 0)
  )

  // Actions
  async function fetchEscalations(visibility?: string, saleStatus?: string, activeOnly = false) {
    isLoading.value = true
    error.value = null

    try {
      let url = '/escalations'
      const params: string[] = []
      if (visibility) params.push(`visibility=${visibility}`)
      if (saleStatus) params.push(`saleStatus=${saleStatus}`)
      if (activeOnly) params.push('active=true')
      if (params.length) url += '?' + params.join('&')

      escalations.value = await apiRequest<Escalation[]>(url)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch escalations'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchCorpEscalations() {
    error.value = null

    try {
      corpEscalations.value = await apiRequest<Escalation[]>('/escalations/corp')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch corp escalations'
    }
  }

  async function fetchPublicEscalations() {
    error.value = null

    try {
      publicEscalations.value = await apiRequest<Escalation[]>('/escalations/public')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch public escalations'
    }
  }

  async function createEscalation(input: CreateEscalationInput) {
    error.value = null

    try {
      const created = await apiRequest<Escalation>('/escalations', {
        method: 'POST',
        body: JSON.stringify(input),
      })

      escalations.value.unshift(created)
      return created
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create escalation'
      throw e
    }
  }

  async function updateEscalation(id: string, updates: Partial<Pick<Escalation, 'visibility' | 'bmStatus' | 'saleStatus' | 'price' | 'notes' | 'type'>>) {
    error.value = null

    try {
      const updated = await apiRequest<Escalation>(`/escalations/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(updates),
      })

      const index = escalations.value.findIndex(e => e.id === id)
      if (index !== -1) {
        escalations.value.splice(index, 1, updated)
      }

      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update escalation'
      throw e
    }
  }

  async function deleteEscalation(id: string) {
    error.value = null

    try {
      await apiRequest(`/escalations/${id}`, { method: 'DELETE' })
      escalations.value = escalations.value.filter(e => e.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete escalation'
      throw e
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    escalations,
    corpEscalations,
    publicEscalations,
    isLoading,
    error,

    // Computed
    activeEscalations,
    counts,
    totalSoldValue,

    // Actions
    fetchEscalations,
    fetchCorpEscalations,
    fetchPublicEscalations,
    createEscalation,
    updateEscalation,
    deleteEscalation,
    clearError,
  }
})
