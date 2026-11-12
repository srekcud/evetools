import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import type { SchedulerHealthEntry } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

const TRACKED_SYNC_TYPES = [
  'industry', 'pve', 'wallet', 'mining', 'ansiblex', 'planetary', 'market-jita', 'market-structure',
  'alert-prices', 'adjusted-prices', 'cost-indices', 'public-contracts', 'corporation-mining-extractions',
  'moon-tax', 'blueprints', 'market-volumes', 'courier-contracts', 'character-orders',
  'mining-operation-recurrences', 'moon-chunk-alerts', 'mining-site-depletion-alerts',
  'notifications-purge', 'market-history-purge', 'shared-lists-purge', 'assets', 'corporation-assets', 'market-alerts',
]

function entryOfType(type: string, triggerable = false): SchedulerHealthEntry {
  return {
    type,
    status: 'ok',
    health: 'healthy',
    startedAt: null,
    completedAt: null,
    message: null,
    expectedInterval: 1800,
    onDemand: false,
    triggerable,
    runs: [],
    successRate24h: null,
  } as SchedulerHealthEntry
}

function renderedTextFor(type: string, locale: TestLocale): string {
  const wrapper = mount(SchedulerHealthTable, {
    props: { entries: [entryOfType(type)], actionLoading: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  return wrapper.text()
}

describe('SchedulerHealthTable sync names', () => {
  it.each(TEST_LOCALES)('writes the %s name of a sync from its type key', (locale) => {
    expect(renderedTextFor('industry', locale)).toContain(messageOf(locale, 'admin.sync.types.industry'))
  })

  it('names a sync differently in French and in English', () => {
    expect(messageOf('fr', 'admin.sync.types.industry')).not.toBe(messageOf('en', 'admin.sync.types.industry'))
  })

  it.each(TRACKED_SYNC_TYPES)('translates the %s sync in both locales', (type) => {
    for (const locale of TEST_LOCALES) {
      expect(messageOf(locale, `admin.sync.types.${type}`)).not.toBe('')
    }
  })
})

function mountTableWith(entries: SchedulerHealthEntry[], actionLoading: string | null = null) {
  return mount(SchedulerHealthTable, {
    props: { entries, actionLoading },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

describe('SchedulerHealthTable manual trigger', () => {
  it('shows a trigger button on each triggerable row only', () => {
    const wrapper = mountTableWith([entryOfType('cost-indices', true), entryOfType('legacy-type', false)])

    expect(wrapper.findAll('button')).toHaveLength(1)
  })

  it('emits the type of the row when its trigger button is clicked', async () => {
    const wrapper = mountTableWith([entryOfType('industry', true), entryOfType('cost-indices', true)])

    await wrapper.findAll('button')[1].trigger('click')

    expect(wrapper.emitted('execute-action')).toEqual([['cost-indices']])
  })

  it('disables the trigger buttons while an action is running', () => {
    const wrapper = mountTableWith([entryOfType('cost-indices', true)], 'cost-indices')

    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
  })
})

const NOW = new Date('2026-11-11T12:00:00Z')
const HOURLY_INTERVAL = 3_600
const MAPPED_TYPE = 'industry-jobs'

enableAutoUnmount(afterEach)

function entry(type: string, overrides: Partial<SchedulerHealthEntry> = {}): SchedulerHealthEntry {
  return {
    type,
    status: 'ok',
    health: 'healthy',
    startedAt: '2026-11-11T11:30:00+00:00',
    completedAt: '2026-11-11T11:30:42+00:00',
    message: null,
    expectedInterval: HOURLY_INTERVAL,
    onDemand: false,
    triggerable: false,
    runs: [],
    successRate24h: null,
    ...overrides,
  }
}

function mountTable(entries: SchedulerHealthEntry[]): VueWrapper {
  const syntheticTypeNames = Object.fromEntries(entries.map(candidate => [candidate.type, candidate.type]))
  const messages = { ...en, admin: { ...en.admin, sync: { ...en.admin.sync, types: { ...en.admin.sync.types, ...syntheticTypeNames } } } }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: messages } })
  return mount(SchedulerHealthTable, {
    props: {
      entries,
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
    const wrapper = mountTable([entry(MAPPED_TYPE, { triggerable: true })])

    await rowFor(wrapper, MAPPED_TYPE).find('button').trigger('click')

    expect(wrapper.emitted('execute-action')?.[0][0]).toBe(MAPPED_TYPE)
  })
})

describe('SchedulerHealthTable ESI throttle note', () => {
  it('shows the ESI throttle note in its header', () => {
    const wrapper = mountTable([entry(MAPPED_TYPE)])

    expect(wrapper.text()).toContain(en.admin.scheduler.throttleEsi)
  })
})
