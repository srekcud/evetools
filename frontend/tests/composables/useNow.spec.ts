import { mount } from '@vue/test-utils'
import { defineComponent, h, type Ref } from 'vue'
import { useNow } from '@/composables/useNow'

const STARTED_AT = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_SECOND = 1_000

function mountWithNow(): { now: Ref<number>; unmount: () => void } {
  let now: Ref<number> | null = null
  const wrapper = mount(defineComponent({
    setup() {
      now = useNow()
      return () => h('span', String(now?.value))
    },
  }))
  if (now === null) throw new Error('useNow was not called')
  return { now, unmount: () => wrapper.unmount() }
}

describe('useNow', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(STARTED_AT)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('moves forward by one second at every tick', async () => {
    const { now, unmount } = mountWithNow()
    const atStart = now.value

    await vi.advanceTimersByTimeAsync(ONE_SECOND)
    const afterOneSecond = now.value
    await vi.advanceTimersByTimeAsync(2 * ONE_SECOND)
    const afterThreeSeconds = now.value
    unmount()

    expect({ atStart, afterOneSecond, afterThreeSeconds }).toEqual({
      atStart: STARTED_AT,
      afterOneSecond: STARTED_AT + ONE_SECOND,
      afterThreeSeconds: STARTED_AT + 3 * ONE_SECOND,
    })
  })

  it('stops moving once the component is unmounted', async () => {
    const { now, unmount } = mountWithNow()
    await vi.advanceTimersByTimeAsync(2 * ONE_SECOND)
    const beforeUnmount = now.value

    unmount()
    await vi.advanceTimersByTimeAsync(5 * ONE_SECOND)

    expect({ beforeUnmount, afterUnmount: now.value }).toEqual({
      beforeUnmount: STARTED_AT + 2 * ONE_SECOND,
      afterUnmount: STARTED_AT + 2 * ONE_SECOND,
    })
  })
})
