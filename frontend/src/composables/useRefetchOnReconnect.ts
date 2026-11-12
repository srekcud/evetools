import { watch } from 'vue'
import { useSyncStore } from '@/stores/sync'

export function useRefetchOnReconnect(refetch: () => unknown): void {
  const syncStore = useSyncStore()
  watch(
    () => syncStore.reconnectedAt,
    (reconnectedAt) => {
      if (reconnectedAt !== null) void refetch()
    },
  )
}
