import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectIskBand from '@/components/group-industry/ProjectIskBand.vue'
import type { IskBand, IskBandCell } from '@/components/group-industry/projectIskBand'

function cell(fields: Partial<IskBandCell> & Pick<IskBandCell, 'key'>): IskBandCell {
  return { amount: 1_500_000, estimate: false, provisional: false, market: null, unsoldProductCount: 0, ...fields }
}

function mountBand(cells: IskBandCell[], locale: 'en' | 'fr' = 'en'): VueWrapper {
  const band: IskBand = { cells }
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ProjectIskBand, { props: { band }, global: { plugins: [i18n] } })
}

function cellOf(wrapper: VueWrapper, key: string) {
  return wrapper.get(`[data-testid="isk-band-${key}"]`)
}

function text(wrapper: VueWrapper, key: string): string {
  return cellOf(wrapper, key).text().replace(/\s/g, ' ')
}

describe('project ISK band view', () => {
  it('renders one cell per figure of the band in order', () => {
    const wrapper = mountBand([cell({ key: 'committed' }), cell({ key: 'onSite' }), cell({ key: 'expected', estimate: true }), cell({ key: 'profit', estimate: true })])

    expect(wrapper.findAll('[data-testid^="isk-band-"]:not([data-testid="isk-band-value"])').map(element => element.attributes('data-testid'))).toEqual([
      'isk-band-committed',
      'isk-band-onSite',
      'isk-band-expected',
      'isk-band-profit',
    ])
  })

  it('labels the figures in English', () => {
    const wrapper = mountBand([cell({ key: 'committed' }), cell({ key: 'onSite' }), cell({ key: 'expected', estimate: true }), cell({ key: 'profit', estimate: true })])

    expect(text(wrapper, 'committed')).toContain('Committed')
    expect(text(wrapper, 'onSite')).toContain('On site')
    expect(text(wrapper, 'expected')).toContain('Expected sales')
    expect(text(wrapper, 'profit')).toContain('Estimated profit')
  })

  it('labels the figures in French', () => {
    const wrapper = mountBand([cell({ key: 'committed' }), cell({ key: 'onSite' }), cell({ key: 'cashed' }), cell({ key: 'profit' })], 'fr')

    expect(text(wrapper, 'committed')).toContain('Engagé')
    expect(text(wrapper, 'onSite')).toContain('Sur place')
    expect(text(wrapper, 'cashed')).toContain('Encaissé')
    expect(text(wrapper, 'profit')).toContain('Profit')
    expect(text(wrapper, 'profit')).not.toContain('estimé')
  })

  it('formats a known amount as ISK', () => {
    const wrapper = mountBand([cell({ key: 'committed', amount: 1_500_000 })])

    expect(text(wrapper, 'committed')).toContain('1.50 M')
  })

  it('marks an estimate as dashed and a known figure as solid', () => {
    const wrapper = mountBand([cell({ key: 'expected', estimate: true }), cell({ key: 'committed' })])

    expect(cellOf(wrapper, 'expected').attributes('data-estimate')).toBe('true')
    expect(cellOf(wrapper, 'expected').find('[data-testid="isk-band-value"]').classes()).toContain('border-dashed')
    expect(cellOf(wrapper, 'committed').attributes('data-estimate')).toBeUndefined()
    expect(cellOf(wrapper, 'committed').find('[data-testid="isk-band-value"]').classes()).not.toContain('border-dashed')
  })

  it('hatches an unknown figure and shows a dash instead of a number', () => {
    const wrapper = mountBand([cell({ key: 'onSite', amount: null })])

    expect(cellOf(wrapper, 'onSite').attributes('data-unknown')).toBe('true')
    expect(text(wrapper, 'onSite')).toContain('—')
    expect(text(wrapper, 'onSite')).not.toContain('---')
  })

  it('does not hatch a known zero', () => {
    const wrapper = mountBand([cell({ key: 'onSite', amount: 0 })])

    expect(cellOf(wrapper, 'onSite').attributes('data-unknown')).toBeUndefined()
  })

  it('colours a known profit green and a known loss red', () => {
    const profit = mountBand([cell({ key: 'profit', amount: 2_000_000 })])
    const loss = mountBand([cell({ key: 'profit', amount: -2_000_000 })])

    expect(cellOf(profit, 'profit').find('[data-testid="isk-band-value"]').classes()).toContain('text-emerald-400')
    expect(cellOf(loss, 'profit').find('[data-testid="isk-band-value"]').classes()).toContain('text-red-400')
  })

  it('labels a provisional profit', () => {
    const wrapper = mountBand([cell({ key: 'profit', estimate: true, provisional: true })])

    expect(text(wrapper, 'profit')).toContain('Provisional profit')
  })

  it('names the market of an expected figure and counts unsold products beside the cashed one', () => {
    const wrapper = mountBand([cell({ key: 'expected', estimate: true, market: 'Perimeter - Tranquility Trade Hub' }), cell({ key: 'cashed', unsoldProductCount: 2 })])

    expect(text(wrapper, 'expected')).toContain('Perimeter - Tranquility Trade Hub')
    expect(text(wrapper, 'cashed')).toContain('2')
  })
})
