import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { LONG_TYPE_NAME, RANKING_INPUT_A, RANKING_INPUT_C, RANKING_PRODUCT_B, withSellPrices, withTypeName, withTypeVolume } from '../../planetaryPlannerFixtures'
import {
  TEMPLATE_B_LINE,
  TEMPLATE_CATALOG,
  TEMPLATE_PRODUCT_M,
  TEMPLATE_VANISHED,
  makeTemplate,
} from '../../planetaryTemplatesFixtures'
import { cellOfTemplate, mountTemplates, postedBodies, rowOfTemplate, templateRows } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const COMMUNITY_TEMPLATES_URL = 'https://github.com/DalShooth/EVE_PI_Templates/tree/main/PlanetaryInteractionTemplates'
const MIX_TEMPLATE = makeTemplate('tpl-mix', 'B and M daily', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }], {}, undefined, '2026-11-21T12:00:00+00:00')
const SAVED_TEMPLATE = makeTemplate('tpl-new', 'Coolant mix', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 40 }], {}, undefined, '2026-11-26T09:00:00+00:00')

describe('PiPlannerTemplates table', () => {
  it('shows the name, the productions with their target a day, the planets, the profit a day, a week and a month, the purchases of the week and the date of a template', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-name').text()).toBe('Barren B line')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-productions').text()).toMatch(/Ranking Product B\s*×\s*120\s*\/\s*day/)
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').text()).toBe('1')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-day').text()).toBe('67.20 K')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-week').text()).toBe('470.40 K')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-month').text()).toBe('2.02 M')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-purchases').text()).toBe('336.00 K')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-date').text()).toMatch(/2026/)
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-date').text()).toMatch(/(?<!\d)20(?!\d)/)
    for (const testId of ['template-planets', 'template-profit-day', 'template-profit-week', 'template-profit-month', 'template-purchases']) {
      expect(cellOfTemplate(wrapper, 'tpl-b-line', testId).attributes('data-state')).toBe('known')
    }
  })

  it('lists every production of a template and adds their profits and purchases, in the order of the store', async () => {
    const { wrapper } = await mountTemplates({ templates: [MIX_TEMPLATE, TEMPLATE_B_LINE] })

    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-mix', 'tpl-b-line'])
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-productions').text()).toMatch(/Ranking Product B\s*×\s*120\s*\/\s*day/)
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-productions').text()).toMatch(/Ranking Product M\s*×\s*18\s*\/\s*day/)
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-profit-day').text()).toBe('75.25 K')
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-profit-week').text()).toBe('526.72 K')
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-profit-month').text()).toBe('2.26 M')
    expect(cellOfTemplate(wrapper, 'tpl-mix', 'template-purchases').text()).toBe('364.35 K')
  })

  it('recomputes the profit of a template from the current prices of the catalog', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 1_100 }) })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-day').text()).toBe('78.72 K')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-week').text()).toBe('551.04 K')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-month').text()).toBe('2.36 M')
  })

  it('shows an unknown profit and unknown purchases, never 0, when a price is missing, while the planets stay known', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_INPUT_C]: null }) })

    for (const testId of ['template-profit-day', 'template-profit-week', 'template-profit-month', 'template-purchases']) {
      const cell = cellOfTemplate(wrapper, 'tpl-b-line', testId)
      expect(cell.attributes('data-state')).toBe('unknown')
      expect(cell.text()).toBe(en.pi.planner.unknown)
    }
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-purchases').attributes('data-incomplete')).toBe('true')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').text()).toBe('1')
  })

  it('shows unknown planets, never 0, when the volume of a type is unknown, while the profit stays known', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE], catalog: withTypeVolume(TEMPLATE_CATALOG, RANKING_INPUT_A, null) })

    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').attributes('data-state')).toBe('unknown')
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-planets').text()).toBe(en.pi.planner.unknown)
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-day').text()).toBe('67.20 K')
  })

  it('marks a template whose product left the catalog as recipe not found with Load and Add disabled and Delete enabled, without disturbing the other rows', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_VANISHED, TEMPLATE_B_LINE] })
    const vanished = rowOfTemplate(wrapper, 'tpl-vanished')

    expect(vanished.get('[data-testid="template-missing"]').text()).not.toBe('')
    expect(vanished.get('[data-testid="template-missing"]').text()).not.toMatch(/^pi\.planner/)
    expect(cellOfTemplate(wrapper, 'tpl-vanished', 'template-name').text()).toBe('Vanished recipe')
    expect(vanished.get('[data-testid="template-load"]').attributes('disabled')).toBeDefined()
    expect(vanished.get('[data-testid="template-add"]').attributes('disabled')).toBeDefined()
    expect(vanished.get('[data-testid="template-delete"]').attributes('disabled')).toBeUndefined()
    expect(rowOfTemplate(wrapper, 'tpl-b-line').find('[data-testid="template-missing"]').exists()).toBe(false)
    expect(cellOfTemplate(wrapper, 'tpl-b-line', 'template-profit-day').text()).toBe('67.20 K')
  })

  it('enables Load, Add and Delete on a template that resolves', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })
    const row = rowOfTemplate(wrapper, 'tpl-b-line')

    for (const testId of ['template-load', 'template-add', 'template-delete']) {
      expect(row.get(`[data-testid="${testId}"]`).attributes('disabled')).toBeUndefined()
    }
  })

  it('invites the player to save a plan while there is no template, with the name field and the save button still shown', async () => {
    const { wrapper } = await mountTemplates()

    expect(wrapper.get('[data-testid="templates-empty"]').text()).not.toBe('')
    expect(templateRows(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-testid="template-name-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="template-save"]').exists()).toBe(true)
  })

  it('hides the empty invitation once a template exists', async () => {
    const { wrapper } = await mountTemplates({ templates: [TEMPLATE_B_LINE] })

    expect(wrapper.find('[data-testid="templates-empty"]').exists()).toBe(false)
  })

  it('links to the community templates as an external link that opens a new tab without opener or referrer', async () => {
    const { wrapper } = await mountTemplates()
    const link = wrapper.get('[data-testid="templates-community-link"]')

    expect(link.element.tagName).toBe('A')
    expect(link.attributes('href')).toBe(COMMUNITY_TEMPLATES_URL)
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
  })

  it('truncates a name of 100 characters and a very long product name inside the row, keeping the full text in the title', async () => {
    const longName = 'N'.repeat(100)
    const template = makeTemplate('tpl-long', longName, [{ typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])
    const { wrapper } = await mountTemplates({ templates: [template], catalog: withTypeName(TEMPLATE_CATALOG, TEMPLATE_PRODUCT_M, LONG_TYPE_NAME) })

    const name = cellOfTemplate(wrapper, 'tpl-long', 'template-name')
    const productions = cellOfTemplate(wrapper, 'tpl-long', 'template-productions')

    expect(name.attributes('title')).toBe(longName)
    expect(name.classes()).toContain('truncate')
    expect(productions.text()).toContain(LONG_TYPE_NAME)
    expect(productions.find(`[title="${LONG_TYPE_NAME}"]`).exists()).toBe(true)
    expect(productions.find(`[title="${LONG_TYPE_NAME}"]`).classes()).toContain('truncate')
  })
})

describe('PiPlannerTemplates save controls', () => {
  it('disables the save button while the plan has no production, whatever the name', async () => {
    const { wrapper } = await mountTemplates()

    await wrapper.get('[data-testid="template-name-input"]').setValue('Coolant mix')

    expect(wrapper.get('[data-testid="template-save"]').attributes('disabled')).toBeDefined()
  })

  it('disables the save button for an empty name, a blank name and a name of 101 characters and enables it for a name of 100 characters', async () => {
    const { wrapper, store } = await mountTemplates()
    store.addProduction(RANKING_PRODUCT_B, 40)
    const input = wrapper.get('[data-testid="template-name-input"]')
    const button = wrapper.get('[data-testid="template-save"]')

    for (const name of ['', '   ', 'a'.repeat(101)]) {
      await input.setValue(name)
      expect(button.attributes('disabled')).toBeDefined()
    }
    await input.setValue('a'.repeat(100))

    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('saves the plan under the typed name and shows the new template first with its figures', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [MIX_TEMPLATE], saved: SAVED_TEMPLATE })
    store.addProduction(RANKING_PRODUCT_B, 40)

    await wrapper.get('[data-testid="template-name-input"]').setValue('Coolant mix')
    await wrapper.get('[data-testid="template-save"]').trigger('click')
    await flushPromises()

    expect(postedBodies()).toEqual([{
      name: 'Coolant mix',
      productions: [{ typeId: RANKING_PRODUCT_B, targetPerDay: 40 }],
      choices: {},
      settings: { purchaseSource: 'jita-sell', saleSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 },
    }])
    expect(templateRows(wrapper).map(row => row.attributes('data-template-id'))).toEqual(['tpl-new', 'tpl-mix'])
    expect(cellOfTemplate(wrapper, 'tpl-new', 'template-name').text()).toBe('Coolant mix')
    expect(cellOfTemplate(wrapper, 'tpl-new', 'template-profit-day').text()).toBe('22.40 K')
  })
})
