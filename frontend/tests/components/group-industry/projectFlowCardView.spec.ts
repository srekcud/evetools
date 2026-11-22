import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCard from '@/components/group-industry/ProjectFlowCard.vue'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import type { FlowCard, FlowGrid } from '@/components/group-industry/projectFlow'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { flowCard, flowGrid, flowRow } from './projectFlowFixtures'

const THREE_HOURS_MS = 3 * 60 * 60 * 1000
const TWENTY_SIX_HOURS_MS = 26 * 60 * 60 * 1000

const ACTIVITY_STRIPES: Record<string, string> = {
  copy: 'border-l-violet-400',
  invention: 'border-l-blue-400',
  reaction: 'border-l-orange-400',
  manufacturing: 'border-l-cyan-400',
  material: 'border-l-slate-400',
  unknown: 'border-l-slate-600',
}

const SOURCE_TEXT: Record<TestLocale, Record<'stock' | 'blueprint' | 'invention', string>> = {
  fr: { stock: 'stock', blueprint: 'blueprint', invention: 'invention' },
  en: { stock: 'stock', blueprint: 'blueprint', invention: 'invention' },
}

const REMAINING_THREE_HOURS: Record<TestLocale, string> = { fr: '03:00 restant', en: '03:00 left' }
const REMAINING_ONE_DAY: Record<TestLocale, string> = { fr: '1 j 02 h restant', en: '1 d 02 h left' }

const LEGEND_LABELS: Record<TestLocale, string[]> = {
  fr: ['Copies', 'Inventions', 'Réactions', 'Fabrication', 'Matériaux'],
  en: ['Copies', 'Inventions', 'Reactions', 'Manufacturing', 'Materials'],
}

enableAutoUnmount(afterEach)

function i18nOf(locale: TestLocale) {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function mountCard(card: FlowCard, locale: TestLocale = 'en'): VueWrapper {
  return mount(ProjectFlowCard, { props: { card }, global: { plugins: [i18nOf(locale)] } })
}

function mountGrid(grid: FlowGrid, locale: TestLocale = 'en'): VueWrapper {
  return mount(ProjectFlowGrid, { props: { grid, personalUse: false }, global: { plugins: [i18nOf(locale)] } })
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

describe('ProjectFlowCard activity colour', () => {
  it.each(Object.entries(ACTIVITY_STRIPES))('draws the left border of a %s card in its activity colour', (activity, stripe) => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Zydrine', typeId: 39, activity: activity as FlowCard['activity'] }))

    expect(wrapper.get('[data-testid="flow-card"]').classes()).toContain(stripe)
  })

  it('keeps the activity colour whatever the state', () => {
    const wrapper = mountCard(flowCard('toBuy', { typeName: 'Zydrine', typeId: 39, activity: 'reaction' }))

    expect(wrapper.get('[data-testid="flow-card"]').classes()).toContain(ACTIVITY_STRIPES.reaction)
  })

  it('turns the border green instead of the activity colour when the card is ready', () => {
    const wrapper = mountCard(flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction', ready: true, progress: 100 }))

    const classes = wrapper.get('[data-testid="flow-card"]').classes()
    expect(classes).toContain('border-l-emerald-400')
    expect(classes).not.toContain(ACTIVITY_STRIPES.reaction)
  })

  it('keeps the state as text next to the colour', () => {
    const wrapper = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction' }))

    expect(squashed(wrapper.text())).toContain('In production')
  })
})

describe('ProjectFlowCard progress bar', () => {
  it('draws the bar at the progress percentage with an accessible value', () => {
    const wrapper = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction', progress: 50 }))

    const bar = wrapper.get('[data-testid="flow-card-progress"]')
    expect(bar.attributes('role')).toBe('progressbar')
    expect(bar.attributes('aria-valuenow')).toBe('50')
    expect(bar.find('span').attributes('style')).toContain('width: 50%')
  })

  it('draws an empty bar, not a missing one, at 0 percent', () => {
    const wrapper = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction', progress: 0 }))

    const bar = wrapper.get('[data-testid="flow-card-progress"]')
    expect(bar.attributes('aria-valuenow')).toBe('0')
    expect(bar.find('span').attributes('style')).toContain('width: 0%')
  })

  it('draws the bar in the activity colour while running and in green once ready', () => {
    const running = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction', progress: 50 }))
    const ready = mountCard(flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540, activity: 'reaction', progress: 100, ready: true }))

    expect(running.get('[data-testid="flow-card-progress"] span').classes()).toContain('bg-orange-400')
    expect(ready.get('[data-testid="flow-card-progress"] span').classes()).toContain('bg-emerald-400')
  })

  it('draws no bar when the progress is unknown', () => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Zydrine', typeId: 39, progress: null }))

    expect(wrapper.find('[data-testid="flow-card-progress"]').exists()).toBe(false)
  })
})

describe.each(TEST_LOCALES)('ProjectFlowCard source and blueprint frame in %s', (locale) => {
  it.each(['stock', 'blueprint', 'invention'] as const)('shows the %s source tag', (source) => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Zydrine', typeId: 39, source }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-source"]').text())).toBe(SOURCE_TEXT[locale][source])
  })

  it('shows no source tag without a source', () => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Zydrine', typeId: 39, source: null }), locale)

    expect(wrapper.find('[data-testid="flow-card-source"]').exists()).toBe(false)
  })

  it('frames the icon of a blueprint copy card', () => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Ishtar Blueprint', typeId: 11_542, bpc: true }), locale)

    expect(wrapper.find('[data-testid="flow-card-bpc"]').exists()).toBe(true)
  })

  it('does not frame the icon of another card', () => {
    const wrapper = mountCard(flowCard('onSite', { typeName: 'Zydrine', typeId: 39, bpc: false }), locale)

    expect(wrapper.find('[data-testid="flow-card-bpc"]').exists()).toBe(false)
  })

  it('writes the time left after the state as a clock under a day and in days beyond', () => {
    const underOneDay = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, remainingMs: THREE_HOURS_MS }), locale)
    const beyondOneDay = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, remainingMs: TWENTY_SIX_HOURS_MS }), locale)

    expect(squashed(underOneDay.get('[data-testid="flow-card-remaining"]').text())).toBe(REMAINING_THREE_HOURS[locale])
    expect(squashed(beyondOneDay.get('[data-testid="flow-card-remaining"]').text())).toBe(REMAINING_ONE_DAY[locale])
  })

  it('writes no time left without a remaining time', () => {
    const wrapper = mountCard(flowCard('inProduction', { typeName: 'Capital Armor Plates', typeId: 11_540, remainingMs: null }), locale)

    expect(wrapper.find('[data-testid="flow-card-remaining"]').exists()).toBe(false)
  })
})

describe.each(TEST_LOCALES)('ProjectFlowGrid activity legend in %s', (locale) => {
  it('lists the five activities above the columns', () => {
    const grid = flowGrid([flowRow({ key: 'station:a' }, { onSite: [flowCard('onSite', { typeName: 'Zydrine', typeId: 39, quantity: 60 })] })])

    const wrapper = mountGrid(grid, locale)

    const legend = wrapper.get('[data-testid="flow-activity-legend"]')
    expect(legend.findAll('[data-testid="flow-activity-legend-item"]').map(item => squashed(item.text()))).toEqual(LEGEND_LABELS[locale])
  })

  it('shows no legend without a row', () => {
    const wrapper = mountGrid(flowGrid([]), locale)

    expect(wrapper.find('[data-testid="flow-activity-legend"]').exists()).toBe(false)
  })

  it('shows no legend when the quantities are unavailable', () => {
    const wrapper = mountGrid(flowGrid([], 'plan_unavailable'), locale)

    expect(wrapper.find('[data-testid="flow-activity-legend"]').exists()).toBe(false)
  })
})
