import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

type WindowAction = 'market' | 'info' | 'contract'

interface OpenWindowResponse {
  success: boolean
  error: string | null
}

const loadingActions = ref<Map<string, boolean>>(new Map())

function getKey(action: WindowAction, targetId: number): string {
  return `${action}_${targetId}`
}

export function useOpenWindow() {
  async function openMarket(typeId: number): Promise<boolean> {
    return performAction('market', typeId, { typeId })
  }

  async function openInfo(targetId: number): Promise<boolean> {
    return performAction('info', targetId, { targetId })
  }

  async function openContract(contractId: number): Promise<boolean> {
    return performAction('contract', contractId, { contractId })
  }

  async function performAction(action: WindowAction, id: number, body: Record<string, number>): Promise<boolean> {
    const key = getKey(action, id)
    if (loadingActions.value.get(key)) return false

    loadingActions.value.set(key, true)
    try {
      const response = await apiRequest<OpenWindowResponse>(
        `/me/open-window/${action}`,
        {
          method: 'POST',
          body: JSON.stringify(body),
        },
      )
      return response.success
    } catch {
      return false
    } finally {
      loadingActions.value.set(key, false)
    }
  }

  function isActionLoading(action: WindowAction, id: number): boolean {
    return loadingActions.value.get(getKey(action, id)) ?? false
  }

  const isLoading = computed(() => {
    for (const value of loadingActions.value.values()) {
      if (value) return true
    }
    return false
  })

  return {
    openMarket,
    openInfo,
    openContract,
    isLoading,
    isActionLoading,
  }
}
