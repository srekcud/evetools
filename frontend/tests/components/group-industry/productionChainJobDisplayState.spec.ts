import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import type { BomItem } from '@/stores/group-industry/types'
import { mountProductionJobRows } from '../../productionJobRows'
import { bomLine, jobMatch } from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_SECOND = 1_000
const ONE_HOUR_TWO_MINUTES_FIVE_SECONDS = 3_725_000
const FAKED_CLOCK = ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] as const
const STATE = '[data-testid="job-match"] [data-testid="job-match-state"]'
const COUNTDOWN = '[data-testid="job-match"] [data-testid="job-match-countdown"]'

function lineWithLinkedJob(status: string, endOffsetMs: number): BomItem {
  return bomLine({
    id: 'job-ishtar',
    typeId: 12_005,
    typeName: 'Ishtar',
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    runs: 4,
    jobMatches: [jobMatch({
      id: 'match-aria',
      bomLineId: 'job-ishtar',
      status,
      esiJobId: 480_012,
      runs: 4,
      characterName: 'Aria Solberg',
      endDate: new Date(NOW + endOffsetMs).toISOString(),
    })],
  })
}

async function mountTreeWithLinkedJob(status: string, endOffsetMs: number, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockReset()
  return mountProductionJobRows({ lines: [lineWithLinkedJob(status, endOffsetMs)], locale })
}

function stateOf(wrapper: VueWrapper): { label: string; state: string | null } {
  const state = wrapper.find(STATE)
  return state.exists() ? { label: state.text(), state: state.attributes('data-state') ?? null } : { label: 'absent', state: null }
}

function countdownOf(wrapper: VueWrapper): string {
  const countdown = wrapper.find(COUNTDOWN)
  return countdown.exists() ? countdown.text() : 'absent'
}

describe('Production tree, display state of a linked job', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: [...FAKED_CLOCK] })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows a running job with a countdown that drops by one second every second', async () => {
    const wrapper = await mountTreeWithLinkedJob('active', ONE_HOUR_TWO_MINUTES_FIVE_SECONDS)
    const atStart = { ...stateOf(wrapper), countdown: countdownOf(wrapper) }

    await vi.advanceTimersByTimeAsync(ONE_SECOND)

    expect({ atStart, afterOneSecond: countdownOf(wrapper) }).toEqual({
      atStart: { label: expect.stringContaining('Running'), state: 'running', countdown: '01:02:05' },
      afterOneSecond: '01:02:04',
    })
  })

  it('shows an active job past its end date as ready to deliver, without countdown', async () => {
    const wrapper = await mountTreeWithLinkedJob('active', -ONE_SECOND)

    expect({ ...stateOf(wrapper), countdown: countdownOf(wrapper) }).toEqual({ label: 'Ready to deliver', state: 'ready', countdown: 'absent' })
  })

  it('shows a delivered job as delivered and the ready to deliver label in French', async () => {
    const delivered = await mountTreeWithLinkedJob('delivered', -ONE_SECOND)
    const readyInFrench = await mountTreeWithLinkedJob('active', -ONE_SECOND, 'fr')

    expect({ delivered: stateOf(delivered).label, readyInFrench: stateOf(readyInFrench).label }).toEqual({
      delivered: 'Delivered',
      readyInFrench: 'À livrer',
    })
  })
})
