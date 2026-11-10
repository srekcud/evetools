import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import StockpileShoppingList from '@/components/industry/StockpileShoppingList.vue'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'
import type { StockpileShoppingItem } from '@/stores/industry/types'

const EmptyView = { render: () => null }
const EVALUATE = '[data-testid="multibuy-evaluate"]'

const PYERITE_VERY_LOW: StockpileShoppingItem = {
  typeId: 35,
  typeName: 'Pyerite',
  stage: 'raw_material',
  stock: 4_000,
  targetQuantity: 14_000,
  deficit: 10_000,
  deficitCost: 116_000,
  percent: 28.5,
}

const ISOGEN_NEARLY_THERE: StockpileShoppingItem = {
  typeId: 37,
  typeName: 'Isogen',
  stage: 'raw_material',
  stock: 900,
  targetQuantity: 1_200,
  deficit: 300,
  deficitCost: 28_935,
  percent: 75,
}

const MORPHITE_MET: StockpileShoppingItem = {
  typeId: 11399,
  typeName: 'Morphite',
  stage: 'intermediate',
  stock: 60,
  targetQuantity: 50,
  deficit: 0,
  deficitCost: 0,
  percent: 100,
}

function mountShoppingList(shoppingList: StockpileShoppingItem[]) {
  setActivePinia(createPinia())
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/appraisal', name: 'appraisal', component: EmptyView }] })
  const push = vi.spyOn(router, 'push')
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(StockpileShoppingList, { global: { plugins: [i18n, router] }, props: { shoppingList } })
  return { wrapper, push }
}

enableAutoUnmount(afterEach)

describe('StockpileShoppingList evaluate bridge', () => {
  it('sends the deficit lines of the displayed list to the Appraisal page, narrowed by the critical filter', async () => {
    const { wrapper, push } = mountShoppingList([PYERITE_VERY_LOW, MORPHITE_MET, ISOGEN_NEARLY_THERE])
    const handoff = useAppraisalHandoffStore()
    const evaluate = wrapper.find(EVALUATE)
    expect(evaluate.exists(), EVALUATE).toBe(true)

    await evaluate.trigger('click')
    await flushPromises()
    const allDeficits = handoff.take()

    const criticalToggle = wrapper.findAll('button').find(button => button.text() === en.industry.stockpile.shoppingList.criticalOnly)
    if (!criticalToggle) throw new Error('No critical filter button')
    await criticalToggle.trigger('click')
    await wrapper.find(EVALUATE).trigger('click')
    await flushPromises()

    expect({ allDeficits, criticalOnly: handoff.take(), pushed: push.mock.calls.map(([target]) => target) }).toEqual({
      allDeficits: 'Pyerite\t10000\nIsogen\t300',
      criticalOnly: 'Pyerite\t10000',
      pushed: [{ name: 'appraisal' }, { name: 'appraisal' }],
    })
  })
})
