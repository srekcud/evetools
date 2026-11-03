import { effectScope, nextTick, ref, type EffectScope, type Ref } from 'vue'
import { UPDATE_FLASH_DURATION_MS, UPDATE_FLASH_SETTLE_MS, useFlash, useUpdateFlash } from '@/composables/useUpdateFlash'

let scope: EffectScope

function flashFor<T>(source: Ref<T>): Readonly<Ref<boolean>> {
  scope = effectScope()
  return scope.run(() => useUpdateFlash(() => source.value)) as Readonly<Ref<boolean>>
}

function settledFlashFor<T>(source: Ref<T>): Readonly<Ref<boolean>> {
  const isFlashing = flashFor(source)
  vi.advanceTimersByTime(UPDATE_FLASH_SETTLE_MS)
  return isFlashing
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  scope.stop()
  vi.useRealTimers()
})

describe('useUpdateFlash', () => {
  it('does not flash on the first load of an empty panel', async () => {
    const colonies = ref<string[]>([])
    const isFlashing = settledFlashFor(colonies)

    colonies.value = ['Colony A']
    await nextTick()

    expect(isFlashing.value).toBe(false)
  })

  it('does not flash when data arrives after a null placeholder', async () => {
    const slots = ref<{ used: number } | null>(null)
    const isFlashing = settledFlashFor(slots)

    slots.value = { used: 3 }
    await nextTick()

    expect(isFlashing.value).toBe(false)
  })

  it('ignores the successive loads of a panel built from several sources right after it is shown', async () => {
    const todoItems = ref(['Reconnect characters'])
    const isFlashing = flashFor(todoItems)

    todoItems.value = ['Reconnect characters', 'Restart extractors']
    await nextTick()
    await nextTick()

    expect(isFlashing.value).toBe(false)
  })

  it('flashes when loaded data is replaced, then stops after the flash duration', async () => {
    const slots = ref<{ used: number } | null>({ used: 3 })
    const isFlashing = settledFlashFor(slots)

    slots.value = { used: 4 }
    await nextTick()
    await nextTick()
    expect(isFlashing.value).toBe(true)

    vi.advanceTimersByTime(UPDATE_FLASH_DURATION_MS)
    expect(isFlashing.value).toBe(false)
  })

  it('restarts the flash when a second update lands during the first one', async () => {
    const slots = ref({ used: 3 })
    const isFlashing = settledFlashFor(slots)

    slots.value = { used: 4 }
    await nextTick()
    await nextTick()
    vi.advanceTimersByTime(UPDATE_FLASH_DURATION_MS - 100)

    slots.value = { used: 5 }
    await nextTick()
    expect(isFlashing.value).toBe(false)
    await nextTick()
    expect(isFlashing.value).toBe(true)

    vi.advanceTimersByTime(UPDATE_FLASH_DURATION_MS - 100)
    expect(isFlashing.value).toBe(true)
    vi.advanceTimersByTime(100)
    expect(isFlashing.value).toBe(false)
  })
})

describe('useFlash', () => {
  it('flashes on demand, without any settle period, then stops after the flash duration', async () => {
    scope = effectScope()
    const { isFlashing, flash } = scope.run(() => useFlash()) as ReturnType<typeof useFlash>

    flash()
    await nextTick()
    expect(isFlashing.value).toBe(true)

    vi.advanceTimersByTime(UPDATE_FLASH_DURATION_MS)
    expect(isFlashing.value).toBe(false)
  })
})
