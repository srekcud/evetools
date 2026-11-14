import { onUnmounted, ref, watch, type Ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { AssetChanges } from '@/types'

export function useAssetChanges(endpoint: Ref<string | null>) {
  const changes = ref<AssetChanges | null>(null)
  const hasError = ref(false)
  let pendingRequest: AbortController | null = null

  function abortPendingRequest() {
    pendingRequest?.abort()
    pendingRequest = null
  }

  async function load() {
    abortPendingRequest()
    const url = endpoint.value
    if (url === null) {
      changes.value = null
      hasError.value = false
      return
    }

    const request = new AbortController()
    pendingRequest = request
    try {
      const data = await apiRequest<AssetChanges>(url, { signal: request.signal })
      if (request.signal.aborted) return
      changes.value = data
      hasError.value = false
    } catch (e) {
      if (request.signal.aborted) return
      changes.value = null
      hasError.value = true
      console.error(e)
    } finally {
      if (pendingRequest === request) pendingRequest = null
    }
  }

  watch(endpoint, () => {
    changes.value = null
    hasError.value = false
    void load()
  }, { immediate: true })

  onUnmounted(abortPendingRequest)

  return { changes, hasError, load }
}
