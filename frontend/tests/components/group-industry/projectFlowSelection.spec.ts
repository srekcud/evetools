import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCard from '@/components/group-industry/ProjectFlowCard.vue'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import type { FlowCard, FlowGrid } from '@/components/group-industry/projectFlow'
import { COMPONENT_X, STATION_B_ID, ZYDRINE, flowCard, flowGrid, flowRow } from './projectFlowFixtures'

const SOURCE_KEY = 'out:11540:azbel'
const UNRELATED_KEY = 'out:39:azbel'

enableAutoUnmount(afterEach)

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function mountCard(card: FlowCard, selected?: boolean): VueWrapper {
  return mount(ProjectFlowCard, { props: { card, ...(selected === undefined ? {} : { selected }) }, global: { plugins: [i18n] } })
}

function mountGrid(grid: FlowGrid, selectedKey?: string | null): VueWrapper {
  return mount(ProjectFlowGrid, { props: { grid, personalUse: false, ...(selectedKey === undefined ? {} : { selectedKey }) }, global: { plugins: [i18n] } })
}

function sourceCard(): FlowCard {
  return flowCard('out', { ...COMPONENT_X, quantity: 40, key: SOURCE_KEY })
}

function expectedTwin(): FlowCard {
  return flowCard('expected', {
    ...COMPONENT_X,
    quantity: 40,
    key: 'expected:out:11540:azbel',
    expected: { origin: 'out', fromKind: 'station', fromName: 'Azbel', cardKey: SOURCE_KEY },
  })
}

function linkedGrid(): FlowGrid {
  return flowGrid([
    flowRow({ key: 'station:a', name: 'Azbel' }, { out: [sourceCard(), flowCard('out', { ...ZYDRINE, quantity: 5, key: UNRELATED_KEY })] }),
    flowRow({ key: 'station:b', name: 'Athanor', locationId: STATION_B_ID }, { toBring: [expectedTwin()] }),
  ])
}

function cardOf(wrapper: VueWrapper, key: string) {
  return wrapper.get(`[data-testid="flow-card"][data-card-key="${key}"]`)
}

describe('ProjectFlowCard selection', () => {
  it('emits select on a click and on Enter or Space, and ignores a click on a button or a link inside the card', async () => {
    const wrapper = mountCard(sourceCard())
    const card = wrapper.get('[data-testid="flow-card"]')

    await card.trigger('click')
    await card.trigger('keydown', { key: 'Enter' })
    await card.trigger('keydown', { key: ' ' })
    expect(wrapper.emitted('select')).toHaveLength(3)

    const innerButton = document.createElement('button')
    const innerLink = document.createElement('a')
    card.element.append(innerButton, innerLink)
    innerButton.click()
    innerLink.click()
    expect(wrapper.emitted('select')).toHaveLength(3)
  })

  it('marks the selected card with data-selected and aria-pressed', () => {
    const selected = mountCard(sourceCard(), true).get('[data-testid="flow-card"]')
    const unselected = mountCard(sourceCard(), false).get('[data-testid="flow-card"]')

    expect(selected.attributes('data-selected')).toBe('true')
    expect(selected.attributes('aria-pressed')).toBe('true')
    expect(unselected.attributes('data-selected')).not.toBe('true')
    expect(unselected.attributes('aria-pressed')).toBe('false')
  })
})

describe('ProjectFlowGrid selection', () => {
  it('emits select with the card key, the source key for an expected card and null on a second click on the selected card', async () => {
    const wrapper = mountGrid(linkedGrid())

    await cardOf(wrapper, UNRELATED_KEY).trigger('click')
    const expectedCard = wrapper.get('[data-testid="flow-card"][data-state="expected"]')
    await expectedCard.trigger('click')

    expect(wrapper.emitted('select')).toEqual([[UNRELATED_KEY], [SOURCE_KEY]])

    const selectedWrapper = mountGrid(linkedGrid(), SOURCE_KEY)
    expect(cardOf(selectedWrapper, SOURCE_KEY).attributes('data-selected')).toBe('true')
    expect(cardOf(selectedWrapper, UNRELATED_KEY).attributes('data-selected')).not.toBe('true')

    await cardOf(selectedWrapper, SOURCE_KEY).trigger('click')
    expect(selectedWrapper.emitted('select')).toEqual([[null]])
  })
})
