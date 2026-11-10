import { computed, ref } from 'vue'
import { useMiningOperationStore, type MiningOperation } from '@/stores/miningOperation'
import { bridgeCardStatus, type BridgeCardStatus } from '@/composables/bridgeCardStatus'

export const BRIDGE_MINING_OPERATIONS_SHOWN = 3

export function useBridgeMiningOperations() {
  const store = useMiningOperationStore()
  const isSettled = ref(false)
  const hasFailed = ref(false)

  const inProgress = computed<MiningOperation[]>(() => {
    if (hasFailed.value || !Array.isArray(store.myOperations)) return []
    return store.myOperations.filter(operation => operation.status === 'in_progress')
  })

  const status = computed<BridgeCardStatus>(() => bridgeCardStatus({
    isSettled: isSettled.value,
    hasFailed: hasFailed.value,
    itemCount: inProgress.value.length,
  }))

  const shown = computed(() => inProgress.value.slice(0, BRIDGE_MINING_OPERATIONS_SHOWN))

  async function load(): Promise<void> {
    await store.fetchMine()
    hasFailed.value = store.error !== null || !Array.isArray(store.myOperations)
    isSettled.value = true
  }

  void load()

  return { status, inProgressCount: computed(() => inProgress.value.length), shown }
}
