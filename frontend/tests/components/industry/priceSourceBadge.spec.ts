import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PriceSourceBadge from '@/components/industry/PriceSourceBadge.vue'
import type { PriceSource } from '@/stores/industry/types'

const JITA_SELL_PERCENTILE_5: PriceSource = { market: 'jita', side: 'sell', method: 'percentile', coveragePercent: null, percentile: 5 }
const JITA_SELL_WEIGHTED_COMPLETE: PriceSource = { market: 'jita', side: 'sell', method: 'weighted', coveragePercent: null }
const JITA_SELL_WEIGHTED_75: PriceSource = { market: 'jita', side: 'sell', method: 'weighted', coveragePercent: 75 }
const JITA_BUY_WEIGHTED_COMPLETE: PriceSource = { market: 'jita', side: 'buy', method: 'weighted', coveragePercent: null }
const STRUCTURE_SELL_LOWEST: PriceSource = { market: 'structure', side: 'sell', method: 'lowest', coveragePercent: null }
const CONTRACTS_SELL_MEDIAN: PriceSource = { market: 'contracts', side: 'sell', method: 'median', coveragePercent: null }

function mountBadge(source: PriceSource | null | undefined, locale: 'en' | 'fr' = 'fr') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(PriceSourceBadge, {
    global: { plugins: [i18n] },
    props: { source },
  })
  return { wrapper, t: i18n.global.t }
}

describe('PriceSourceBadge', () => {
  it('shows nothing for a null or an undefined source', () => {
    for (const source of [null, undefined]) {
      const { wrapper } = mountBadge(source)

      expect(wrapper.text()).toBe('')
      expect(wrapper.html()).not.toContain('<span')
    }
  })

  it.each([
    ['fr', 'vente'],
    ['en', 'sell'],
  ] as const)('labels the percentile 5.0 of the Jita sell book "Jita · P5" and explains the market, the side and the method in the tooltip (%s)', (locale, sideWord) => {
    const { wrapper } = mountBadge(JITA_SELL_PERCENTILE_5, locale)

    expect(wrapper.text()).toBe('Jita · P5')
    const tooltip = wrapper.get('[title]').attributes('title')!
    expect(tooltip).toContain('Jita')
    expect(tooltip).toContain(sideWord)
    expect(tooltip).toContain('percentile')
  })

  it('labels a weighted Jita sell price without coverage marker when the order book is complete', () => {
    const { wrapper, t } = mountBadge(JITA_SELL_WEIGHTED_COMPLETE)

    expect(wrapper.text()).toBe('Jita · pondéré')
    expect(wrapper.text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })

  it('shows the coverage marker of a partially covered price next to the label and its coverage in the tooltip', () => {
    const { wrapper, t } = mountBadge(JITA_SELL_WEIGHTED_75)

    expect(wrapper.text()).toContain('Jita · pondéré')
    expect(wrapper.text()).toContain(t('industry.partialPrice.marker', { percent: 75 }))
    expect(wrapper.get('[title]').attributes('title')).toContain('75')
  })

  it('names the buy side in the label of a Jita buy price', () => {
    expect(mountBadge(JITA_BUY_WEIGHTED_COMPLETE).wrapper.text()).toBe('Jita achat · pondéré')
  })

  it('labels the structure and contracts sources with their market and method', () => {
    expect(mountBadge(STRUCTURE_SELL_LOWEST).wrapper.text()).toBe('Structure · plus bas')
    expect(mountBadge(CONTRACTS_SELL_MEDIAN).wrapper.text()).toBe('Contrats · médiane')
  })

  it('truncates the label with an ellipsis and keeps the full source in the tooltip', () => {
    const { wrapper } = mountBadge(JITA_SELL_WEIGHTED_75)

    const badge = wrapper.find('[title]')
    expect(badge.exists()).toBe(true)
    expect(badge.classes()).toContain('truncate')
    expect(badge.attributes('title')).not.toBe('')
  })
})
