import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ValuationSourceLabel from '@/components/mining-module/ValuationSourceLabel.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { ValuationSource } from '@/stores/miningValuationProfile'
import { JITA_BUY_COMPRESSED, PERIMETER_STRUCTURE_NAME } from '../ledger/miningValuationProfileHarness'

const STRUCTURE_SELL_RAW: ValuationSource = { market: 'structure', side: 'sell', form: 'raw', structureName: PERIMETER_STRUCTURE_NAME }

function mountLabel(source: ValuationSource | null, locale: 'en' | 'fr' = 'en') {
  return mount(ValuationSourceLabel, {
    props: { source },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

describe('ValuationSourceLabel', () => {
  it('writes the market, the side and the form of the source', () => {
    expect(mountLabel(JITA_BUY_COMPRESSED).text()).toBe('Jita · Buy · Compressed')
  })

  it('writes the structure name in place of the market, truncated with the full name as tooltip', () => {
    const wrapper = mountLabel(STRUCTURE_SELL_RAW)

    expect(wrapper.text()).toBe(`${PERIMETER_STRUCTURE_NAME} · Sell · Raw`)
    const name = wrapper.find(`[title="${PERIMETER_STRUCTURE_NAME}"]`)
    expect(name.exists()).toBe(true)
    expect(name.classes()).toContain('truncate')
  })

  it('says the source is unknown, in English and in French, when the entry has none', () => {
    expect([mountLabel(null).text(), mountLabel(null, 'fr').text()]).toEqual(['unknown source', 'source inconnue'])
  })
})
