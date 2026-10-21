import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StockpileShoppingList from '@/components/industry/StockpileShoppingList.vue'
import type { StockpileShoppingItem } from '@/stores/industry/types'

const oreA: StockpileShoppingItem = {
  typeId: 3003,
  typeName: 'Ore A',
  stage: 'raw_material',
  stock: 4,
  targetQuantity: 14,
  deficit: 10,
  deficitCost: 20,
  percent: 28.5,
}

const oreB: StockpileShoppingItem = {
  typeId: 3006,
  typeName: 'Ore B',
  stage: 'raw_material',
  stock: 9,
  targetQuantity: 12,
  deficit: 3,
  deficitCost: 22.5,
  percent: 75,
}

const metFrame: StockpileShoppingItem = {
  typeId: 3002,
  typeName: 'Frame',
  stage: 'component',
  stock: 10,
  targetQuantity: 10,
  deficit: 0,
  deficitCost: 0,
  percent: 100,
}

function mountShoppingList(shoppingList: StockpileShoppingItem[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(StockpileShoppingList, {
    global: { plugins: [i18n] },
    props: { shoppingList },
  })
}

describe('StockpileShoppingList deficit', () => {
  let writeText: ReturnType<typeof vi.fn>

  beforeEach(() => {
    writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  })

  it('counts the items below target from their deficit', () => {
    const wrapper = mountShoppingList([oreA, oreB])

    expect(wrapper.text()).toContain('2 items below target')
  })

  it('leaves an item without deficit out of the items below target', () => {
    const wrapper = mountShoppingList([oreA, metFrame, oreB])

    expect(wrapper.text()).toContain('2 items below target')
  })

  it('copies one line per item with its name and its deficit', async () => {
    const wrapper = mountShoppingList([oreA, oreB])

    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('Ore A\t10\nOre B\t3')
  })

  it('leaves an item without deficit out of the copied text', async () => {
    const wrapper = mountShoppingList([oreA, metFrame, oreB])

    await wrapper.findAll('button')[0].trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('Ore A\t10\nOre B\t3')
  })
})
