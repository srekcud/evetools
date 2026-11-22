import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import type { FlowCardState, FlowGrid } from '@/components/group-industry/projectFlow'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'
import {
  JITA_ID,
  JITA_NAME,
  STATION_B_ID,
  flowCard,
  flowGrid,
  flowRow,
} from './projectFlowFixtures'

const COLUMN_HEADERS: Record<TestLocale, string[]> = {
  fr: ['À apporter', 'Sur place', 'En fabrication', 'Sorti', 'Vendu'],
  en: ['To bring', 'On site', 'In production', 'Out', 'Sold'],
}

const PERSONAL_USE_LAST_HEADER: Record<TestLocale, string> = { fr: 'Remis', en: 'Handed over' }
const EMPTY_TEXT: Record<TestLocale, string> = { fr: 'Rien à afficher pour l\'instant', en: 'Nothing to show yet' }
const HERE_BADGE: Record<TestLocale, string> = { fr: 'ici', en: 'here' }
const UNNAMED_STATION: Record<TestLocale, string> = { fr: 'station sans nom', en: 'unnamed station' }
const UNRESOLVED_ROW: Record<TestLocale, string> = { fr: 'Lieu à choisir', en: 'Place to choose' }
const FOLDED_ON_SITE: Record<TestLocale, string> = { fr: '+ 4 sur place', en: '+ 4 on site' }
const FOLDED_CONSUMED: Record<TestLocale, string> = { fr: '+ 4 consommés', en: '+ 4 consumed' }
const COLLAPSE: Record<TestLocale, string> = { fr: '\u2212 replier', en: '\u2212 collapse' }
const TWELVE_THOUSAND_FIVE_HUNDRED: Record<TestLocale, string> = { fr: '12 500', en: '12,500' }

const CARD_STATES: FlowCardState[] = ['toBring', 'inTransit', 'onSite', 'consumed', 'inProduction', 'out', 'toBuy', 'toLaunch', 'sold']

enableAutoUnmount(afterEach)

function mountGrid(grid: FlowGrid, locale: TestLocale, personalUse = false): VueWrapper {
  return mount(ProjectFlowGrid, {
    props: { grid, personalUse },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function texts(wrapper: Pick<VueWrapper, 'findAll'>, selector: string): string[] {
  return wrapper.findAll(selector).map(element => squashed(element.text()))
}

function cardTexts(wrapper: VueWrapper, rowIndex: number, column: string): string[] {
  const rows = wrapper.findAll('[data-testid="flow-row"]')
  if (rowIndex >= rows.length) return []
  const row = rows[rowIndex]
  return row.find(`[data-testid="flow-cell-${column}"]`).findAll('[data-testid="flow-card"]').map(card => squashed(card.text()))
}

function firstCardText(wrapper: VueWrapper, rowIndex: number, column: string): string {
  return cardTexts(wrapper, rowIndex, column).join('')
}

function toggleOf(wrapper: VueWrapper, rowIndex: number, column: string): ReturnType<VueWrapper['find']> {
  const row = wrapper.findAll('[data-testid="flow-row"]')[rowIndex]
  return row.find(`[data-testid="flow-cell-${column}"] [data-testid="flow-fold-toggle"]`)
}

function toggleText(wrapper: VueWrapper, rowIndex: number, column: string): string {
  const toggle = wrapper.findAll('[data-testid="flow-row"]').length > rowIndex ? toggleOf(wrapper, rowIndex, column) : null
  return toggle !== null && toggle.exists() ? squashed(toggle.text()) : ''
}

async function clickToggle(wrapper: VueWrapper, rowIndex: number, column: string): Promise<void> {
  expect(toggleText(wrapper, rowIndex, column)).not.toBe('')
  await toggleOf(wrapper, rowIndex, column).trigger('click')
}

function oneToBringCard(): FlowGrid {
  return flowGrid([flowRow({ key: 'station:a' }, { toBring: [flowCard('toBring', { typeName: 'Zydrine', typeId: 39, quantity: 40 })] })])
}

function onSiteCellOfFive(): FlowGrid {
  return flowGrid([flowRow({ key: 'station:a' }, {
    onSite: [
      flowCard('toBuy', { typeName: 'Tritanium', typeId: 34, quantity: 400 }),
      flowCard('onSite', { typeName: 'a-Pyerite', typeId: 35, quantity: 10 }),
      flowCard('onSite', { typeName: 'b-Isogen', typeId: 37, quantity: 20 }),
      flowCard('consumed', { typeName: 'c-Nocxium', typeId: 38, quantity: 30 }),
      flowCard('onSite', { typeName: 'd-Megacyte', typeId: 40, quantity: 40 }),
    ],
  })])
}

describe.each(TEST_LOCALES)('ProjectFlowGrid in %s', (locale) => {
  it('shows the five translated flow columns, in order, and no tab and no table', () => {
    const wrapper = mountGrid(oneToBringCard(), locale)

    expect(texts(wrapper, '[data-testid="flow-column-header"]')).toEqual(COLUMN_HEADERS[locale])
    expect(wrapper.find('[role="tab"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('names the last column after what the personal use hands over instead of what is sold', () => {
    const wrapper = mountGrid(oneToBringCard(), locale, true)

    expect(texts(wrapper, '[data-testid="flow-column-header"]')).toEqual([...COLUMN_HEADERS[locale].slice(0, 4), PERSONAL_USE_LAST_HEADER[locale]])
  })

  it('labels one row per station and badges the row where a character is docked', () => {
    const wrapper = mountGrid(flowGrid([
      flowRow({ key: 'station:a', name: 'Azbel', here: true }, { onSite: [flowCard('onSite', { typeName: 'Zydrine', typeId: 39, quantity: 60 })] }),
      flowRow({ key: 'market:jita', kind: 'market', locationId: JITA_ID, name: JITA_NAME, here: null }, { onSite: [flowCard('toBuy', { typeName: 'Tritanium', typeId: 34, quantity: 400 })] }),
      flowRow({ key: 'station:b', locationId: STATION_B_ID, name: 'Athanor', here: null }, { toBring: [flowCard('toBring', { typeName: 'Mexallon', typeId: 36, quantity: 90 })] }),
    ]), locale)

    const rows = wrapper.findAll('[data-testid="flow-row"]')
    expect(rows).toHaveLength(3)
    expect(texts(wrapper, '[data-testid="flow-row-label"]')).toEqual(['Azbel', JITA_NAME, 'Athanor'])
    expect(rows[0].find('[data-testid="flow-row-here"]').text()).toBe(HERE_BADGE[locale])
    expect(rows[1].find('[data-testid="flow-row-here"]').exists()).toBe(false)
    expect(rows[2].find('[data-testid="flow-row-here"]').exists()).toBe(false)
  })

  it('writes a translated label for a station without name and for the unresolved row', () => {
    const wrapper = mountGrid(flowGrid([
      flowRow({ key: 'station:b', locationId: STATION_B_ID, name: null }, { toBring: [flowCard('toBring', { typeName: 'Mexallon', typeId: 36, quantity: 90 })] }),
      flowRow({ key: 'unresolved', kind: 'unresolved', locationId: null, name: null }, { onSite: [flowCard('toBuy', { typeName: 'Zydrine', typeId: 39, quantity: 90, unknownReason: 'market_not_chosen' })] }),
    ]), locale)

    const labels = texts(wrapper, '[data-testid="flow-row-label"]')
    expect(labels).toHaveLength(2)
    expect(labels[0].toLowerCase()).toBe(UNNAMED_STATION[locale])
    expect(labels[1]).toBe(UNRESOLVED_ROW[locale])
  })

  it('shows the name and the formatted quantity of each card in its own column, in order', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'station:a' }, {
      toBring: [
        flowCard('toBring', { typeName: 'Zydrine', typeId: 39, quantity: 12_500 }),
        flowCard('inTransit', { typeName: 'Tritanium', typeId: 34, quantity: 25 }),
      ],
      inProduction: [flowCard('inProduction', { typeName: 'Mexallon', typeId: 36, quantity: 7 })],
    })]), locale)

    const toBring = cardTexts(wrapper, 0, 'toBring')
    expect(toBring).toHaveLength(2)
    expect(toBring[0]).toContain('Zydrine')
    expect(toBring[0]).toContain(TWELVE_THOUSAND_FIVE_HUNDRED[locale])
    expect(toBring[1]).toContain('Tritanium')
    expect(toBring[1]).toContain('25')
    expect(cardTexts(wrapper, 0, 'inProduction')).toHaveLength(1)
    expect(firstCardText(wrapper, 0, 'inProduction')).toContain('Mexallon')
    expect(cardTexts(wrapper, 0, 'onSite')).toEqual([])
  })

  it('shows the translated state label on every kind of card', () => {
    CARD_STATES.forEach((state) => {
      const wrapper = mountGrid(flowGrid([flowRow({ key: `station:${state}` }, { onSite: [flowCard(state, { typeName: 'Zydrine', typeId: 39, quantity: 5 })] })]), locale)

      expect(cardTexts(wrapper, 0, 'onSite'), state).toHaveLength(1)
      expect(firstCardText(wrapper, 0, 'onSite'), state).toContain(messageOf(locale, `groupIndustry.flow.states.${state}`))
    })
  })

  it('shows a translated fallback name for a card whose type has no name', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'market:jita', kind: 'market', locationId: JITA_ID, name: JITA_NAME }, { onSite: [flowCard('toBuy', { typeName: null, typeId: 35, quantity: 70 })] })]), locale)

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(1)
    const card = firstCardText(wrapper, 0, 'onSite')
    expect(card).toContain(messageOf(locale, 'groupIndustry.flow.unnamedType'))
    expect(card).toContain('70')
  })

  it('shows the translated reason on an unknown card and never a zero', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'station:a' }, {
      onSite: [flowCard('unknown', { typeName: 'Tritanium', typeId: 34, quantity: null, unknownReason: 'corporation_assets_not_synced' })],
    })]), locale)

    const card = firstCardText(wrapper, 0, 'onSite')
    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(1)
    expect(card).toContain('Tritanium')
    expect(card).toContain(messageOf(locale, 'groupIndustry.flow.unknownReasons.corporation_assets_not_synced'))
    expect(card).not.toContain('corporation_assets_not_synced')
    expect(card).not.toMatch(/\d/)
  })

  it('shows the translated reason of the place and the quantity on a card to buy at an unknown place', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'unresolved', kind: 'unresolved', locationId: null, name: null }, {
      onSite: [flowCard('toBuy', { typeName: 'Zydrine', typeId: 39, quantity: 90, unknownReason: 'market_not_chosen' })],
    })]), locale)

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(1)
    const card = firstCardText(wrapper, 0, 'onSite')
    expect(card).toContain(messageOf(locale, 'groupIndustry.flow.unknownReasons.market_not_chosen'))
    expect(card).not.toContain('market_not_chosen')
    expect(card).toContain('90')
  })

  it('folds the quiet cards of a crowded cell behind a button and opens them on click', async () => {
    const wrapper = mountGrid(onSiteCellOfFive(), locale)

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(1)
    const closedToggle = toggleText(wrapper, 0, 'onSite')
    expect(closedToggle).toContain(FOLDED_ON_SITE[locale])
    expect(closedToggle).toContain('a-Pyerite, b-Isogen, c-Nocxium')
    expect(closedToggle).toContain('…')
    expect(closedToggle).not.toContain('d-Megacyte')

    await clickToggle(wrapper, 0, 'onSite')

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(5)
    expect(toggleText(wrapper, 0, 'onSite')).toContain(COLLAPSE[locale])

    await clickToggle(wrapper, 0, 'onSite')

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(1)
    expect(toggleText(wrapper, 0, 'onSite')).toContain(FOLDED_ON_SITE[locale])
  })

  it('names the button after the consumed cards when all the folded cards are consumed', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'station:a' }, {
      onSite: [
        flowCard('consumed', { typeName: 'a-Pyerite', typeId: 35, quantity: 10 }),
        flowCard('consumed', { typeName: 'b-Isogen', typeId: 37, quantity: 20 }),
        flowCard('consumed', { typeName: 'c-Nocxium', typeId: 38, quantity: 30 }),
        flowCard('consumed', { typeName: 'd-Megacyte', typeId: 40, quantity: 40 }),
      ],
    })]), locale)

    expect(toggleText(wrapper, 0, 'onSite')).toContain(FOLDED_CONSUMED[locale])
  })

  it('keeps the fold state of each cell apart', async () => {
    const crowded = onSiteCellOfFive().rows[0]
    const wrapper = mountGrid(flowGrid([
      flowRow({ key: 'station:a', name: 'Azbel' }, crowded.cells),
      flowRow({ key: 'station:b', locationId: STATION_B_ID, name: 'Athanor' }, crowded.cells),
    ]), locale)

    await clickToggle(wrapper, 0, 'onSite')

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(5)
    expect(cardTexts(wrapper, 1, 'onSite')).toHaveLength(1)
  })

  it('shows every card and no button for a cell of three cards', () => {
    const wrapper = mountGrid(flowGrid([flowRow({ key: 'station:a' }, {
      onSite: [
        flowCard('onSite', { typeName: 'a-Pyerite', typeId: 35, quantity: 10 }),
        flowCard('onSite', { typeName: 'b-Isogen', typeId: 37, quantity: 20 }),
        flowCard('consumed', { typeName: 'c-Nocxium', typeId: 38, quantity: 30 }),
      ],
    })]), locale)

    expect(cardTexts(wrapper, 0, 'onSite')).toHaveLength(3)
    expect(wrapper.find('[data-testid="flow-fold-toggle"]').exists()).toBe(false)
  })

  it('shows the unavailable banner with the translated reason above the rows that remain', () => {
    const grid = oneToBringCard()
    grid.unavailableReason = 'plan_unavailable'
    const wrapper = mountGrid(grid, locale)

    const banner = wrapper.find('[data-testid="flow-unavailable"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain(messageOf(locale, 'groupIndustry.flow.unknownReasons.plan_unavailable'))
    expect(banner.text()).not.toContain('plan_unavailable')
    expect(wrapper.findAll('[data-testid="flow-row"]')).toHaveLength(1)
  })

  it('shows the unavailable banner, not a silent empty grid, when no row remains', () => {
    const wrapper = mountGrid(flowGrid([], 'plan_unavailable'), locale)

    expect(wrapper.find('[data-testid="flow-unavailable"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="flow-unavailable"]').text()).toContain(messageOf(locale, 'groupIndustry.flow.unknownReasons.plan_unavailable'))
    expect(wrapper.findAll('[data-testid="flow-row"]')).toHaveLength(0)
  })

  it('shows no banner and no empty message when the plan is available and has rows', () => {
    const wrapper = mountGrid(oneToBringCard(), locale)

    expect(wrapper.findAll('[data-testid="flow-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="flow-unavailable"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="flow-empty"]').exists()).toBe(false)
  })

  it('shows the empty message and no empty grid when there is no row', () => {
    const wrapper = mountGrid(flowGrid([]), locale)

    expect(wrapper.find('[data-testid="flow-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="flow-empty"]').text()).toBe(EMPTY_TEXT[locale])
    expect(wrapper.findAll('[data-testid="flow-row"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="flow-column-header"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="flow-unavailable"]').exists()).toBe(false)
  })
})
