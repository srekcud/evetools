import { computed, type ComputedRef } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function useKnownCorporation(): ComputedRef<boolean> {
  const authStore = useAuthStore()

  return computed(() => {
    const corporationId = authStore.user?.corporationId
    return corporationId != null && corporationId > 0
  })
}
