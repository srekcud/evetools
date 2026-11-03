import { nextTick, onScopeDispose, readonly, ref, watch, type Ref, type WatchSource } from 'vue'

export const UPDATE_FLASH_DURATION_MS = 1200

function isLoaded(value: unknown): boolean {
  if (value === null || value === undefined) return false
  return !Array.isArray(value) || value.length > 0
}

export function useUpdateFlash<T>(source: WatchSource<T>): Readonly<Ref<boolean>> {
  const isFlashing = ref(false)
  let stopTimer: ReturnType<typeof setTimeout> | null = null

  function clearTimer(): void {
    if (stopTimer !== null) {
      clearTimeout(stopTimer)
      stopTimer = null
    }
  }

  watch(source, async (_current, previous) => {
    if (!isLoaded(previous)) return
    clearTimer()
    isFlashing.value = false
    await nextTick()
    isFlashing.value = true
    stopTimer = setTimeout(() => {
      isFlashing.value = false
      stopTimer = null
    }, UPDATE_FLASH_DURATION_MS)
  })

  onScopeDispose(clearTimer)

  return readonly(isFlashing)
}
