import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCard from '@/components/group-industry/ProjectFlowCard.vue'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import type { FlowCard, FlowCardExpected, FlowGrid } from '@/components/group-industry/projectFlow'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { STATION_B_ID, flowCard, flowGrid, flowRow } from './projectFlowFixtures'

const THREE_HOURS_MS = 3 * 60 * 60 * 1000

const EXPECTED_TAG: Record<TestLocale, string> = { fr: 'attendu', en: 'Expected' }
const WHY_OUT: Record<TestLocale, string> = { fr: 'sorti à Azbel · à emporter', en: 'out at Azbel · to carry' }
const WHY_IN_PRODUCTION: Record<TestLocale, string> = { fr: 'en fabrication à Azbel', en: 'in production at Azbel' }
const WHY_TO_LAUNCH: Record<TestLocale, string> = { fr: 'à lancer à Azbel', en: 'to launch at Azbel' }
const WHY_TO_BUY: Record<TestLocale, string> = { fr: 'à acheter à Jita', en: 'to buy at Jita' }
const WHY_UNRESOLVED: Record<TestLocale, string> = { fr: 'à lancer à Lieu à choisir', en: 'to launch at Place to choose' }
const REMAINING_THREE_HOURS: Record<TestLocale, string> = { fr: '03:00 restant', en: '03:00 left' }
const FOLDED_EXPECTED: Record<TestLocale, string> = { fr: '+ 3 attendus : b-Isogen, c-Nocxium, d-Megacyte', en: '+ 3 expected: b-Isogen, c-Nocxium, d-Megacyte' }
const COLLAPSE: Record<TestLocale, string> = { fr: '− replier', en: '− collapse' }

enableAutoUnmount(afterEach)

function expectedCard(expected: Partial<FlowCardExpected>, fields: Partial<FlowCard> = {}): FlowCard {
  return flowCard('expected', {
    typeName: 'Capital Armor Plates',
    typeId: 11_540,
    quantity: 40,
    activity: 'reaction',
    key: 'expected:out:11540:azbel',
    expected: { origin: 'out', fromKind: 'station', fromName: 'Azbel', cardKey: 'out:11540:azbel', ...expected },
    ...fields,
  })
}

function mountCard(card: FlowCard, locale: TestLocale = 'en'): VueWrapper {
  return mount(ProjectFlowCard, { props: { card }, global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] } })
}

function mountGrid(grid: FlowGrid, locale: TestLocale = 'en'): VueWrapper {
  return mount(ProjectFlowGrid, { props: { grid, personalUse: false }, global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] } })
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function crowdedExpectedCell(): FlowGrid {
  const names = ['a-Pyerite', 'b-Isogen', 'c-Nocxium', 'd-Megacyte']
  const origins = ['out', 'inProduction', 'toLaunch', 'toBuy'] as const
  return flowGrid([flowRow({ key: 'station:b', name: 'Athanor', locationId: STATION_B_ID }, {
    toBring: names.map((typeName, index) => expectedCard({ origin: origins[index], cardKey: `source:${typeName}` }, { typeName, key: `expected:${typeName}` })),
  })])
}

function linkedGrid(): FlowGrid {
  const source = flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540, quantity: 40, key: 'out:11540:azbel' })
  const unrelated = flowCard('out', { typeName: 'Zydrine', typeId: 39, quantity: 5, key: 'out:39:azbel' })
  return flowGrid([
    flowRow({ key: 'station:a', name: 'Azbel' }, { out: [source, unrelated] }),
    flowRow({ key: 'station:b', name: 'Athanor', locationId: STATION_B_ID }, { toBring: [expectedCard({})] }),
  ])
}

function highlighted(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="flow-card"]').filter(card => card.attributes('data-highlighted') === 'true').map(card => card.attributes('data-card-key') ?? '')
}

describe.each(TEST_LOCALES)('ProjectFlowCard expected in %s', (locale) => {
  it('draws the card dashed and transparent, with the activity colour kept on its left border', () => {
    const wrapper = mountCard(expectedCard({}), locale)

    const card = wrapper.get('[data-testid="flow-card"]')
    expect(card.attributes('data-state')).toBe('expected')
    expect(card.classes()).toContain('border-dashed')
    expect(card.classes()).toContain('border-l-orange-400')
    expect(card.classes()).toContain('bg-transparent')
  })

  it('tags the card as expected in place of a source tag', () => {
    const wrapper = mountCard(expectedCard({}), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected"]').text())).toBe(EXPECTED_TAG[locale])
    expect(wrapper.find('[data-testid="flow-card-source"]').exists()).toBe(false)
  })

  it('names the origin of what is out', () => {
    const wrapper = mountCard(expectedCard({ origin: 'out' }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected-why"]').text())).toBe(WHY_OUT[locale])
  })

  it('names the origin of what is in production and shows the time left', () => {
    const wrapper = mountCard(expectedCard({ origin: 'inProduction' }, { remainingMs: THREE_HOURS_MS }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected-why"]').text())).toBe(WHY_IN_PRODUCTION[locale])
    expect(squashed(wrapper.get('[data-testid="flow-card-remaining"]').text())).toBe(REMAINING_THREE_HOURS[locale])
  })

  it('names the origin of what is to launch', () => {
    const wrapper = mountCard(expectedCard({ origin: 'toLaunch' }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected-why"]').text())).toBe(WHY_TO_LAUNCH[locale])
  })

  it('names the market of what is to buy', () => {
    const wrapper = mountCard(expectedCard({ origin: 'toBuy', fromKind: 'market', fromName: 'Jita' }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected-why"]').text())).toBe(WHY_TO_BUY[locale])
  })

  it('names an unresolved origin as the place to choose', () => {
    const wrapper = mountCard(expectedCard({ origin: 'toLaunch', fromKind: 'unresolved', fromName: null }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card-expected-why"]').text())).toBe(WHY_UNRESOLVED[locale])
  })

  it('shows no progress bar and no green border', () => {
    const wrapper = mountCard(expectedCard({ origin: 'out' }, { progress: 100, ready: true }), locale)

    expect(wrapper.find('[data-testid="flow-card-progress"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="flow-card"]').classes()).not.toContain('border-l-emerald-400')
  })

  it('shows no quantity when it is unknown', () => {
    const wrapper = mountCard(expectedCard({}, { quantity: null }), locale)

    expect(squashed(wrapper.get('[data-testid="flow-card"]').text())).not.toMatch(/\d{2}/)
  })

  it('carries the key of its source card', () => {
    const wrapper = mountCard(expectedCard({ cardKey: 'out:11540:azbel' }), locale)

    expect(wrapper.get('[data-testid="flow-card"]').attributes('data-card-key')).toBe('out:11540:azbel')
  })
})

describe('ProjectFlowCard of a source', () => {
  it('carries its own key', () => {
    const wrapper = mountCard(flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540, key: 'out:11540:azbel' }))

    expect(wrapper.get('[data-testid="flow-card"]').attributes('data-card-key')).toBe('out:11540:azbel')
  })

  it('is not highlighted by default and is when asked', async () => {
    const wrapper = mount(ProjectFlowCard, {
      props: { card: flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540 }), highlighted: false },
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
    })
    expect(wrapper.get('[data-testid="flow-card"]').attributes('data-highlighted')).toBe('false')

    await wrapper.setProps({ highlighted: true })

    expect(wrapper.get('[data-testid="flow-card"]').attributes('data-highlighted')).toBe('true')
  })

  it('tells when the pointer or the focus enters and leaves', async () => {
    const wrapper = mountCard(flowCard('out', { typeName: 'Capital Armor Plates', typeId: 11_540 }))

    await wrapper.get('[data-testid="flow-card"]').trigger('mouseenter')
    await wrapper.get('[data-testid="flow-card"]').trigger('mouseleave')
    await wrapper.get('[data-testid="flow-card"]').trigger('focusin')
    await wrapper.get('[data-testid="flow-card"]').trigger('focusout')

    expect(wrapper.emitted('highlight')).toEqual([[true], [false], [true], [false]])
  })
})

describe.each(TEST_LOCALES)('ProjectFlowGrid expected cards in %s', (locale) => {
  it('lists the expected cards in the to bring cell', () => {
    const wrapper = mountGrid(linkedGrid(), locale)

    const cell = wrapper.findAll('[data-testid="flow-row"]')[1].get('[data-testid="flow-cell-toBring"]')
    expect(cell.findAll('[data-testid="flow-card"]').map(card => card.attributes('data-state'))).toEqual(['expected'])
  })

  it('highlights the source card and its expected card while the pointer is on the expected card', async () => {
    const wrapper = mountGrid(linkedGrid(), locale)

    await wrapper.findAll('[data-testid="flow-card"]')[2].trigger('mouseenter')

    expect(highlighted(wrapper)).toEqual(['out:11540:azbel', 'out:11540:azbel'])
  })

  it('highlights the expected card while the pointer is on the source card', async () => {
    const wrapper = mountGrid(linkedGrid(), locale)

    await wrapper.findAll('[data-testid="flow-card"]')[0].trigger('mouseenter')

    expect(highlighted(wrapper)).toEqual(['out:11540:azbel', 'out:11540:azbel'])
  })

  it('clears the highlight when the pointer leaves', async () => {
    const wrapper = mountGrid(linkedGrid(), locale)
    await wrapper.findAll('[data-testid="flow-card"]')[2].trigger('mouseenter')

    await wrapper.findAll('[data-testid="flow-card"]')[2].trigger('mouseleave')

    expect(highlighted(wrapper)).toEqual([])
  })

  it('highlights nothing for a card that has no expected card', async () => {
    const wrapper = mountGrid(linkedGrid(), locale)

    await wrapper.findAll('[data-testid="flow-card"]')[1].trigger('mouseenter')

    expect(highlighted(wrapper)).toEqual([])
  })

  it('folds the expected cards not out beyond three and keeps the ready one visible', () => {
    const wrapper = mountGrid(crowdedExpectedCell(), locale)

    const cell = wrapper.get('[data-testid="flow-cell-toBring"]')
    expect(cell.findAll('[data-testid="flow-card"]')).toHaveLength(1)
    expect(squashed(cell.get('[data-testid="flow-fold-toggle"]').text())).toBe(FOLDED_EXPECTED[locale])
  })

  it('opens the folded expected cards and offers to collapse them', async () => {
    const wrapper = mountGrid(crowdedExpectedCell(), locale)

    await wrapper.get('[data-testid="flow-fold-toggle"]').trigger('click')

    const cell = wrapper.get('[data-testid="flow-cell-toBring"]')
    expect(cell.findAll('[data-testid="flow-card"]')).toHaveLength(4)
    expect(squashed(cell.get('[data-testid="flow-fold-toggle"]').text())).toBe(COLLAPSE[locale])
  })
})
