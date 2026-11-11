import { mount } from '@vue/test-utils'
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
