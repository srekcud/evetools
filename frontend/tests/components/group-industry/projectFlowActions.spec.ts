import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowActions from '@/components/group-industry/ProjectFlowActions.vue'
import type { PlacedAction, PlacedActionCode } from '@/stores/group-industry/types'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'
import {
  ANYWHERE_PLACE,
  AZBEL_PLACE,
  JITA_PLACE,
  placedAction,
  unknownPlace,
} from '../../todoPlaceFixtures'

const HERE_BADGE: Record<TestLocale, string> = { fr: 'ici', en: 'here' }
const ACTION_CODES: PlacedActionCode[] = ['launch_job', 'source_material', 'review_contributions', 'review_join_requests']

const LAUNCH_CAPITAL_PLATES = placedAction({ id: 'a1', code: 'launch_job', place: AZBEL_PLACE, typeId: 11_540, typeName: 'Capital Armor Plates', quantity: 5, here: true })
const LAUNCH_MEXALLON_REACTION = placedAction({ id: 'a2', code: 'launch_job', place: AZBEL_PLACE, typeId: 16_670, typeName: 'Crystalline Carbonide', quantity: 12, here: true })
const BUY_TRITANIUM = placedAction({ id: 'a3', code: 'source_material', place: JITA_PLACE, typeId: 34, typeName: 'Tritanium', quantity: 400, here: false })
const BUY_ZYDRINE_NOWHERE = placedAction({ id: 'a4', code: 'source_material', place: unknownPlace('market_not_chosen'), typeId: 39, typeName: 'Zydrine', quantity: 90 })
const REVIEW_DEPOSITS = placedAction({ id: 'a5', code: 'review_contributions', place: ANYWHERE_PLACE, quantity: 4 })
const REVIEW_REQUESTS = placedAction({ id: 'a6', code: 'review_join_requests', place: ANYWHERE_PLACE, quantity: 2 })

enableAutoUnmount(afterEach)

function mountActions(actions: PlacedAction[], locale: TestLocale, state: { loading?: boolean; error?: string | null } = {}): VueWrapper {
  return mount(ProjectFlowActions, {
    props: { actions, loading: state.loading ?? false, error: state.error ?? null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function placeBlocks(wrapper: VueWrapper): VueWrapper[] {
  return wrapper.findAll('[data-testid="flow-actions-place"]') as unknown as VueWrapper[]
}

function titles(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="flow-actions-place-title"]').map(title => squashed(title.text()))
}

function rowsOf(block: VueWrapper): string[] {
  return block.findAll('[data-testid="flow-action-row"]').map(row => squashed(row.text()))
}

describe.each(TEST_LOCALES)('ProjectFlowActions in %s', (locale) => {
  it('ranges the actions by place, in the order of the server, one block per place', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES, BUY_TRITANIUM, LAUNCH_MEXALLON_REACTION, BUY_ZYDRINE_NOWHERE, REVIEW_DEPOSITS, REVIEW_REQUESTS], locale)

    const blocks = placeBlocks(wrapper)
    expect(blocks).toHaveLength(4)
    const blockTitles = titles(wrapper)
    expect(blockTitles[0]).toContain('Azbel')
    expect(blockTitles[1]).toContain('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
    expect(blockTitles[2]).toContain(messageOf(locale, 'groupIndustry.flow.unknownReasons.market_not_chosen'))
    expect(blockTitles[3]).toContain(messageOf(locale, 'groupIndustry.flow.actions.anywhere'))
    expect(rowsOf(blocks[0])).toHaveLength(2)
    expect(rowsOf(blocks[1])).toHaveLength(1)
    expect(rowsOf(blocks[2])).toHaveLength(1)
    expect(rowsOf(blocks[3])).toHaveLength(2)
  })

  it('badges the place where a character is docked and no other', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES, BUY_TRITANIUM, BUY_ZYDRINE_NOWHERE], locale)

    const blocks = placeBlocks(wrapper)
    expect(blocks).toHaveLength(3)
    expect(blocks[0].find('[data-testid="flow-actions-here"]').text()).toBe(HERE_BADGE[locale])
    expect(blocks[1].find('[data-testid="flow-actions-here"]').exists()).toBe(false)
    expect(blocks[2].find('[data-testid="flow-actions-here"]').exists()).toBe(false)
  })

  it('writes one row per action with the verb of its code, the type name and the quantity', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES, LAUNCH_MEXALLON_REACTION, BUY_TRITANIUM], locale)

    const blocks = placeBlocks(wrapper)
    expect(blocks).toHaveLength(2)
    const azbelRows = rowsOf(blocks[0])
    expect(azbelRows).toHaveLength(2)
    expect(azbelRows[0]).toContain(messageOf(locale, 'groupIndustry.flow.actions.verbs.launch_job'))
    expect(azbelRows[0]).toContain('Capital Armor Plates')
    expect(azbelRows[0]).toContain('5')
    expect(azbelRows[1]).toContain('Crystalline Carbonide')
    expect(azbelRows[1]).toContain('12')
    const jitaRows = rowsOf(blocks[1])
    expect(jitaRows).toHaveLength(1)
    expect(jitaRows[0]).toContain(messageOf(locale, 'groupIndustry.flow.actions.verbs.source_material'))
    expect(jitaRows[0]).toContain('Tritanium')
    expect(jitaRows[0]).toContain('400')
  })

  it('uses a different verb for each action code', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES, BUY_TRITANIUM, REVIEW_DEPOSITS, REVIEW_REQUESTS], locale)

    const rows = placeBlocks(wrapper).flatMap(rowsOf)
    expect(rows).toHaveLength(4)
    const verbs = ACTION_CODES.map(code => messageOf(locale, `groupIndustry.flow.actions.verbs.${code}`))
    expect(new Set(verbs).size).toBe(4)
    expect(rows[0]).toContain(verbs[0])
    expect(rows[1]).toContain(verbs[1])
    expect(rows[2]).toContain(verbs[2])
    expect(rows[3]).toContain(verbs[3])
    expect(rows[2]).toContain('4')
    expect(rows[3]).toContain('2')
  })

  it('writes a translated fallback name for an action without type name and never a zero for a missing quantity', () => {
    const wrapper = mountActions([placedAction({ id: 'a7', code: 'source_material', place: JITA_PLACE, typeId: 35, typeName: null, quantity: null })], locale)

    const rows = placeBlocks(wrapper).flatMap(rowsOf)
    expect(rows).toHaveLength(1)
    expect(rows[0]).toContain(messageOf(locale, 'groupIndustry.flow.unnamedType'))
    expect(rows[0]).not.toMatch(/\d/)
  })

  it('shows the empty message when there is no action', () => {
    const wrapper = mountActions([], locale)

    expect(wrapper.find('[data-testid="flow-actions-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="flow-actions-empty"]').text()).toBe(messageOf(locale, 'groupIndustry.flow.actions.empty'))
    expect(placeBlocks(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-testid="flow-actions-error"]').exists()).toBe(false)
  })

  it('shows the error message and never the empty list when the actions could not be read', () => {
    const wrapper = mountActions([], locale, { error: 'Failed to fetch the actions' })

    expect(wrapper.find('[data-testid="flow-actions-error"]').exists()).toBe(true)
    expect(squashed(wrapper.find('[data-testid="flow-actions-error"]').text())).not.toBe('')
    expect(wrapper.find('[data-testid="flow-actions-empty"]').exists()).toBe(false)
  })

  it('shows the actions and neither the error nor the empty message when the read worked', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES], locale)

    expect(placeBlocks(wrapper)).toHaveLength(1)
    expect(wrapper.find('[data-testid="flow-actions-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="flow-actions-empty"]').exists()).toBe(false)
  })

  it('has no action button, the action is done in the game', () => {
    const wrapper = mountActions([LAUNCH_CAPITAL_PLATES, BUY_TRITANIUM, REVIEW_DEPOSITS], locale)

    expect(placeBlocks(wrapper)).toHaveLength(3)
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
