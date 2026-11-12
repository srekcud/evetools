import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import type { SchedulerHealthEntry, SchedulerHealthRun } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

enableAutoUnmount(afterEach)

const RUN_SQUARE = '[data-testid="scheduler-run"]'
const SUCCESS_RATE = '[data-testid="scheduler-success-rate"]'

function run(overrides: Partial<SchedulerHealthRun> = {}): SchedulerHealthRun {
  return {
    startedAt: '2026-11-12T08:00:00+00:00',
    completedAt: '2026-11-12T08:00:42+00:00',
    status: 'ok',
    message: null,
    stuck: false,
    ...overrides,
  }
}

function entryWith(overrides: Partial<SchedulerHealthEntry> = {}): SchedulerHealthEntry {
  return {
    type: 'industry',
    status: 'ok',
    health: 'healthy',
    startedAt: '2026-11-12T08:00:00+00:00',
    completedAt: '2026-11-12T08:00:42+00:00',
    message: null,
    expectedInterval: 1800,
    onDemand: false,
    triggerable: false,
    runs: [],
    successRate24h: null,
    ...overrides,
  }
}

function mountTable(entry: SchedulerHealthEntry, locale: TestLocale = 'en'): VueWrapper {
  return mount(SchedulerHealthTable, {
    props: { entries: [entry], actionLoading: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('SchedulerHealthTable run history', () => {
  it('draws one square per run, the oldest on the left and the newest on the right', () => {
    const newestFirst = [
      run({ status: 'running', completedAt: null }),
      run({ status: 'error' }),
      run({ status: 'ok' }),
    ]

    const squares = mountTable(entryWith({ runs: newestFirst })).findAll(RUN_SQUARE)

    expect(squares.map(square => square.attributes('data-status'))).toEqual(['ok', 'error', 'running'])
  })

  it('draws no square for a type without run', () => {
    expect(mountTable(entryWith({ runs: [] })).findAll(RUN_SQUARE)).toHaveLength(0)
  })

  it('shows the duration and the message of a run on hover', () => {
    const wrapper = mountTable(entryWith({ runs: [run({ status: 'error', message: 'ESI timeout' })] }))

    const title = wrapper.find(RUN_SQUARE).attributes('title') ?? ''

    expect(title).toContain('42s')
    expect(title).toContain('ESI timeout')
  })

  it('shows no duration for a run that is still running', () => {
    const wrapper = mountTable(entryWith({ runs: [run({ status: 'running', completedAt: null })] }))

    expect(wrapper.find(RUN_SQUARE).attributes('title')).toContain('--')
  })

  it.each([
    [92, '92 %'],
    [0, '0 %'],
    [100, '100 %'],
  ])('shows a 24 h success rate of %i as %s', (rate, expectedText) => {
    expect(mountTable(entryWith({ successRate24h: rate })).find(SUCCESS_RATE).text()).toBe(expectedText)
  })

  it('shows -- instead of a rate when no run has finished in the last 24 h', () => {
    expect(mountTable(entryWith({ successRate24h: null })).find(SUCCESS_RATE).text()).toBe('--')
  })

  it.each(TEST_LOCALES)('titles the history column in %s', (locale) => {
    const headers = mountTable(entryWith(), locale).findAll('th').map(header => header.text())

    expect(headers).toContain(messageOf(locale, 'admin.sync.history'))
  })

  it('names the history differently in French and in English', () => {
    expect(messageOf('fr', 'admin.sync.history')).not.toBe(messageOf('en', 'admin.sync.history'))
  })
})
