import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileShoppingList from '@/components/industry/StockpileShoppingList.vue'
import type { StockpileShoppingItem } from '@/stores/industry/types'

const ESTIMATED_COST_COLUMN = 5

const MEXALLON_BELOW_TARGET: StockpileShoppingItem = {
  typeId: 36,
  typeName: 'Mexallon',
  stage: 'raw_material',
  stock: 4,
  targetQuantity: 10,
  deficit: 6,
  deficitCost: 300,
  percent: 40,
}

const UNPRICED_COMPONENT_BELOW_TARGET: StockpileShoppingItem = {
  typeId: 57486,
  typeName: 'Unpriced Component',
  stage: 'component',
  stock: 40,
  targetQuantity: 100,
  deficit: 60,
  deficitCost: null,
  percent: 40,
}

const NITROGEN_ISOTOPES_BELOW_TARGET: StockpileShoppingItem = {
  typeId: 17888,
  typeName: 'Nitrogen Isotopes',
  stage: 'raw_material',
  stock: 100,
  targetQuantity: 405,
  deficit: 305,
  deficitCost: 152_500,
  percent: 24,
}

const ROBOTICS_NEARLY_AT_TARGET: StockpileShoppingItem = {
  typeId: 9848,
  typeName: 'Robotics',
  stage: 'raw_material',
  stock: 3,
  targetQuantity: 4,
  deficit: 1,
  deficitCost: null,
  percent: 75,
}

function mountShoppingList(shoppingList: StockpileShoppingItem[], locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StockpileShoppingList, {
    global: { plugins: [i18n] },
    props: { shoppingList },
  })
  return { wrapper, t: i18n.global.t }
}

function estimatedCostCell(wrapper: VueWrapper, typeName: string) {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  expect(row, `row ${typeName}`).toBeDefined()
  return row!.findAll('td')[ESTIMATED_COST_COLUMN]!
}

describe('StockpileShoppingList with a deficit cost unknown', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the deficit cost of a target without a price as unknown, next to a known deficit cost (%s)', (locale, unknownValueTitle) => {
    const { wrapper } = mountShoppingList([MEXALLON_BELOW_TARGET, UNPRICED_COMPONENT_BELOW_TARGET], locale)

    const unpricedCost = estimatedCostCell(wrapper, 'Unpriced Component')
    expect(unpricedCost.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
    expect(unpricedCost.text()).not.toMatch(/\d/)
    expect(estimatedCostCell(wrapper, 'Mexallon').text()).toBe('300')
  })

  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the total deficit cost as unknown, never as the sum of the known deficit costs (%s)', (locale, unknownValueTitle) => {
    const { wrapper } = mountShoppingList([MEXALLON_BELOW_TARGET, UNPRICED_COMPONENT_BELOW_TARGET], locale)

    const totalDeficitCost = wrapper.get('tfoot')
    expect(totalDeficitCost.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
    expect(totalDeficitCost.text()).not.toMatch(/\d/)
  })

  it('knows the total deficit cost again once the critical-only filter leaves out the target without a price', async () => {
    const { wrapper, t } = mountShoppingList([MEXALLON_BELOW_TARGET, NITROGEN_ISOTOPES_BELOW_TARGET, ROBOTICS_NEARLY_AT_TARGET])

    expect(wrapper.get('tfoot').find('[title="Unknown value"]').exists()).toBe(true)

    const criticalOnly = wrapper.findAll('button').find(button => button.text() === t('industry.stockpile.shoppingList.criticalOnly'))
    expect(criticalOnly, 'critical only filter').toBeDefined()
    await criticalOnly!.trigger('click')

    expect(wrapper.get('tfoot').find('[title="Unknown value"]').exists()).toBe(false)
    expect(wrapper.get('tfoot').text()).toContain('152.80 K')
  })
})
