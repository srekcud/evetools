import { nextTick, onScopeDispose, readonly, ref, watch, type Ref, type WatchSource } from 'vue'

export const UPDATE_FLASH_DURATION_MS = 1200
export const UPDATE_FLASH_SETTLE_MS = 3000

interface Flash {
  isFlashing: Readonly<Ref<boolean>>
  flash: () => Promise<void>
}

function isLoaded(value: unknown): boolean {
  if (value === null || value === undefined) return false
  return !Array.isArray(value) || value.length > 0
}

export function useFlash(): Flash {
  const isFlashing = ref(false)
  let stopTimer: ReturnType<typeof setTimeout> | null = null

  function clearTimer(): void {
    if (stopTimer !== null) {
      clearTimeout(stopTimer)
      stopTimer = null
    }
  }

  async function flash(): Promise<void> {
    clearTimer()
    isFlashing.value = false
    await nextTick()
    isFlashing.value = true
    stopTimer = setTimeout(() => {
      isFlashing.value = false
      stopTimer = null
    }, UPDATE_FLASH_DURATION_MS)
  }

  onScopeDispose(clearTimer)

  return { isFlashing: readonly(isFlashing), flash }
}

export function useUpdateFlash<T>(source: WatchSource<T>): Readonly<Ref<boolean>> {
  const { isFlashing, flash } = useFlash()
  let isSettled = false
  const settleTimer = setTimeout(() => {
    isSettled = true
  }, UPDATE_FLASH_SETTLE_MS)

  watch(source, (_current, previous) => {
    if (isSettled && isLoaded(previous)) flash()
  })

  onScopeDispose(() => clearTimeout(settleTimer))

  return isFlashing
}
