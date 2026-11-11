import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import type { SchedulerHealthEntry } from '@/stores/admin'

const NOW = new Date('2026-11-11T12:00:00Z')
const HOURLY_INTERVAL = 3_600
const MAPPED_TYPE = 'industry-jobs'

enableAutoUnmount(afterEach)

function entry(type: string, overrides: Partial<SchedulerHealthEntry> = {}): SchedulerHealthEntry {
  return {
    type,
    label: type,
    status: 'ok',
    health: 'healthy',
    startedAt: '2026-11-11T11:30:00+00:00',
    completedAt: '2026-11-11T11:30:42+00:00',
    message: null,
    expectedInterval: HOURLY_INTERVAL,
    onDemand: false,
    ...overrides,
  }
}

function mountTable(entries: SchedulerHealthEntry[]): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(SchedulerHealthTable, {
    props: {
      entries,
      syncActionMap: { [MAPPED_TYPE]: { key: 'industry-jobs-sync', action: async () => ({ success: true, message: 'ok' }) } },
      actionLoading: null,
    },
    global: { plugins: [i18n] },
  })
}

function rowFor(wrapper: VueWrapper, label: string) {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(label))
  if (row === undefined) throw new Error(`No row for ${label}`)
  return row
}

describe('SchedulerHealthTable', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('lists the most severe rows first', () => {
    const wrapper = mountTable([
      entry('on-demand-sync', { health: 'on-demand', expectedInterval: null, onDemand: true }),
      entry('healthy-sync'),
      entry('stale-sync', { health: 'stale' }),
      entry('failed-sync', { status: 'error' }),
    ])

    const labels = wrapper.findAll('tbody tr').map(row => row.find('td span').text())

    expect(labels).toEqual(['failed-sync', 'stale-sync', 'healthy-sync', 'on-demand-sync'])
  })

  it('shows the next run column and the run duration column', () => {
    const wrapper = mountTable([entry('any-sync')])

    expect(wrapper.text()).toContain(en.admin.sync.nextRun)
    expect(wrapper.text()).toContain(en.admin.sync.duration)
  })

  it('shows when the next run is due', () => {
    const wrapper = mountTable([entry('soon-sync', { completedAt: '2026-11-11T11:30:00+00:00' })])

    expect(rowFor(wrapper, 'soon-sync').find('[data-testid="scheduler-next-run"]').text()).toBe('in 30m')
  })

  it('flags an overdue next run', () => {
    const wrapper = mountTable([entry('late-sync', { health: 'late', completedAt: '2026-11-11T10:00:00+00:00' })])

    const nextRun = rowFor(wrapper, 'late-sync').find('[data-testid="scheduler-next-run"]')

    expect(nextRun.text()).toBe('overdue by 1h')
    expect(nextRun.classes()).toContain('text-red-400')
  })

  it.each([
    ['on-demand', { health: 'on-demand' as const, expectedInterval: null, onDemand: true }],
    ['never-run', { status: 'unknown' as const, health: 'unknown' as const, startedAt: null, completedAt: null }],
  ])('shows no next run for the %s row', (label, overrides) => {
    const wrapper = mountTable([entry(`${label}-sync`, overrides)])

    expect(rowFor(wrapper, `${label}-sync`).find('[data-testid="scheduler-next-run"]').text()).toBe('--')
  })

  it('shows the on-demand label for a sync whose interval is absent from the payload', () => {
    const onDemandEntry = entry('absent-interval-sync', { health: 'on-demand', onDemand: true })
    Reflect.deleteProperty(onDemandEntry, 'expectedInterval')

    const row = rowFor(mountTable([onDemandEntry]), 'absent-interval-sync')

    expect(row.text()).toContain(`(${en.admin.health.onDemand})`)
    expect(row.text()).not.toContain('NaN')
    expect(row.find('[data-testid="scheduler-next-run"]').text()).toBe('--')
  })

  it('keeps showing the interval of a scheduled sync', () => {
    const row = rowFor(mountTable([entry('scheduled-sync')]), 'scheduled-sync')

    expect(row.text()).toContain('(1h)')
  })

  it('shows the duration of the last run', () => {
    const wrapper = mountTable([entry('timed-sync', { startedAt: '2026-11-11T11:00:00+00:00', completedAt: '2026-11-11T11:02:00+00:00' })])

    expect(rowFor(wrapper, 'timed-sync').find('[data-testid="scheduler-duration"]').text()).toBe('2m')
  })

  it('shows no duration while the task is running', () => {
    const wrapper = mountTable([entry('running-sync', { status: 'running', health: 'running' })])

    expect(rowFor(wrapper, 'running-sync').find('[data-testid="scheduler-duration"]').text()).toBe('--')
  })

  it('still triggers a sync from the row action', async () => {
    const wrapper = mountTable([entry(MAPPED_TYPE)])

    await rowFor(wrapper, MAPPED_TYPE).find('button').trigger('click')

    expect(wrapper.emitted('execute-action')?.[0][0]).toBe('industry-jobs-sync')
  })
})

describe('SchedulerHealthTable ESI throttle note', () => {
  it('shows the ESI throttle note in its header', () => {
    const wrapper = mountTable([entry(MAPPED_TYPE)])

    expect(wrapper.text()).toContain(en.admin.scheduler.throttleEsi)
  })
})
