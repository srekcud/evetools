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

function entryOfType(type: string): SchedulerHealthEntry {
  return {
    type,
    status: 'ok',
    health: 'healthy',
    startedAt: null,
    completedAt: null,
    message: null,
    expectedInterval: 1800,
    onDemand: false,
  } as SchedulerHealthEntry
}

function renderedTextFor(type: string, locale: TestLocale): string {
  const wrapper = mount(SchedulerHealthTable, {
    props: { entries: [entryOfType(type)], syncActionMap: {}, actionLoading: null },
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
