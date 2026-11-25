import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import { apiRequest } from '@/services/api'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import {
  AQUEOUS_LIQUIDS,
  BASE_METALS,
  COOLANT,
  HEAVY_METALS,
  LONG_TYPE_NAME,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  withSellPrices,
  withTypeName,
} from '../../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountTab(catalog: PlannerCatalog = PLANNER_CATALOG, failure: Error | null = null) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (failure) throw failure
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    return { id: 'planner', ...catalog }
  }) as never)
  const wrapper = mount(PiPlannerTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountTab>>

async function addProduction(wrapper: Wrapper, typeId: number) {
  await wrapper.get('[data-testid="planner-add-select"]').setValue(String(typeId))
  await flushPromises()
}

function rows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="planner-tree-row"]')
}

function rowOf(wrapper: Wrapper, typeId: number, nth = 0) {
  const row = rows(wrapper).filter(candidate => candidate.attributes('data-type-id') === String(typeId))[nth]
  if (!row) throw new Error(`No tree row for ${typeId}`)
  return row
}

function summaryText(wrapper: Wrapper, testId: string): string {
  return wrapper.get(`[data-testid="${testId}"]`).text()
}

function lineTexts(wrapper: Wrapper, containerTestId: string, lineSelector: string): string[][] {
  return wrapper.findAll(`[data-testid="${containerTestId}"] ${lineSelector}`).map(line => line.findAll('span').map(span => span.text()))
}

function factoryLines(wrapper: Wrapper): string[][] {
  return wrapper.findAll('[data-testid="summary-factories"] li').map(line => [line.attributes('data-tier') as string, line.findAll('span')[1].text()])
}

function cell(wrapper: Wrapper, typeId: number, testId: string, nth = 0): string {
  return rowOf(wrapper, typeId, nth).get(`[data-testid="${testId}"]`).text()
}

function productionRows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="planner-production-row"]')
}

function rootTypeId(wrapper: Wrapper): number {
  return Number(rows(wrapper)[0].attributes('data-type-id'))
}

enableAutoUnmount(afterEach)

describe('PiPlannerTab', () => {
  it('loads the planner resource and the account and invites the player to add a production while there is none', async () => {
    const wrapper = await mountTab()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/planner', '/planetary/planner/account'])
    expect(wrapper.find('[data-testid="planner-empty"]').exists()).toBe(true)
    expect(rows(wrapper)).toHaveLength(0)
  })

  it('shows the tree of an added production indented down to the raw materials with the P1 bought and the rows below it inactive', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, ROBOTICS)

    expect(wrapper.find('[data-testid="planner-empty"]').exists()).toBe(false)
    expect(rows(wrapper).map(row => [Number(row.attributes('data-type-id')), Number(row.attributes('data-depth')), row.attributes('data-active')])).toEqual([
      [ROBOTICS, 0, 'true'],
      [COOLANT, 1, 'true'],
      [WATER, 2, 'true'],
      [AQUEOUS_LIQUIDS, 3, 'false'],
      [REACTIVE_METALS, 2, 'true'],
      [BASE_METALS, 3, 'false'],
      [MECHANICAL_PARTS, 1, 'true'],
      [WATER, 2, 'true'],
      [AQUEOUS_LIQUIDS, 3, 'false'],
      [TOXIC_METALS, 2, 'true'],
      [HEAVY_METALS, 3, 'false'],
    ])
    expect(rowOf(wrapper, COOLANT).get('[aria-pressed="true"]').attributes('data-choice')).toBe('produce')
    expect(rowOf(wrapper, WATER).get('[aria-pressed="true"]').attributes('data-choice')).toBe('buy')
  })

  it('updates the summary when a choice is switched to buy', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)

    expect(summaryText(wrapper, 'summary-unit-cost')).toContain('10.56 K')
    expect(summaryText(wrapper, 'summary-profit')).toContain('1.40 M')
    expect(summaryText(wrapper, 'summary-purchases')).toContain('4.50 M')

    await rowOf(wrapper, COOLANT).get('[data-choice="buy"]').trigger('click')
    await flushPromises()

    expect(summaryText(wrapper, 'summary-unit-cost')).toContain('8.43 K')
    expect(summaryText(wrapper, 'summary-profit')).toContain('1.55 M')
    expect(summaryText(wrapper, 'summary-purchases')).toContain('3.49 M')
  })

  it('shows an unknown cost without star, an unknown profit and an incomplete purchase line when a price is missing', async () => {
    const wrapper = await mountTab(withSellPrices(PLANNER_CATALOG, { [REACTIVE_METALS]: null }))

    await addProduction(wrapper, ROBOTICS)
    const coolantCost = rowOf(wrapper, COOLANT).get('[data-testid="tree-produce-cost"]')

    expect(coolantCost.attributes('data-state')).toBe('unknown')
    expect(coolantCost.text()).not.toMatch(/\d/)
    expect(rowOf(wrapper, COOLANT).find('[data-testid="tree-star"]').exists()).toBe(false)
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tree-star"]').attributes('data-star')).toBe('produce')
    expect(wrapper.get('[data-testid="summary-unit-cost"]').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="summary-profit"]').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="summary-profit"]').text()).not.toMatch(/\d/)
    expect(wrapper.get('[data-testid="summary-purchases"]').attributes('data-incomplete')).toBe('true')
  })

  it('truncates a very long type name inside its row and keeps the full name in the title', async () => {
    const wrapper = await mountTab(withTypeName(PLANNER_CATALOG, COOLANT, LONG_TYPE_NAME))

    await addProduction(wrapper, ROBOTICS)
    const name = rowOf(wrapper, COOLANT).get(`[data-testid="tree-name"][title="${LONG_TYPE_NAME}"]`)

    expect(name.text()).toContain(LONG_TYPE_NAME)
    expect(name.classes()).toContain('truncate')
  })

  it('shows the error of a failing read in the banner', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, new Error('planner unavailable'))

    expect(wrapper.findComponent(ErrorBanner).props('message')).toBe('planner unavailable')
  })

  it('exposes the exact figures of the summary, not a longer number that merely contains them', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)

    expect(summaryText(wrapper, 'summary-unit-cost')).toBe('10.56 K')
    expect(summaryText(wrapper, 'summary-profit')).toBe('1.40 M')
    expect(summaryText(wrapper, 'summary-purchases')).toMatch(/(?<![\d.,])4\.50 M(?![\d.,])/)
  })

  it('lists the factories of each tier in the summary', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, ROBOTICS)

    expect(factoryLines(wrapper)).toEqual([['P4', '0'], ['P3', '1'], ['P2', '4'], ['P1', '0']])
  })

  it('lists each purchase of the week with its quantity and its amount', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, ROBOTICS)

    expect(lineTexts(wrapper, 'summary-purchases', '[data-testid="summary-purchase-line"]')).toEqual([
      ['Water', '26,880', '1.61 M'],
      ['Reactive Metals', '13,440', '1.88 M'],
      ['Toxic Metals', '13,440', '1.01 M'],
    ])
  })

  it('lists the raw extraction per hour of each resource and counts the P1 factories once the P1 are made', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)

    await rowOf(wrapper, WATER).get('[data-choice="produce"]').trigger('click')
    await rowOf(wrapper, TOXIC_METALS).get('[data-choice="produce"]').trigger('click')
    await flushPromises()

    expect(lineTexts(wrapper, 'summary-extraction', 'li')).toEqual([['Aqueous Liquids', '24,000'], ['Heavy Metals', '12,000']])
    expect(factoryLines(wrapper)).toEqual([['P4', '0'], ['P3', '1'], ['P2', '4'], ['P1', '6']])
  })

  it('shows per row the quantity per day, the factories, the make cost and the price, and none of the money columns on a raw resource', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, ROBOTICS)

    expect([cell(wrapper, ROBOTICS, 'tree-per-day'), cell(wrapper, ROBOTICS, 'tree-factories'), cell(wrapper, ROBOTICS, 'tree-produce-cost'), cell(wrapper, ROBOTICS, 'tree-price')]).toEqual(['72', '1', '10.56 K', '30.00 K'])
    expect([cell(wrapper, COOLANT, 'tree-per-day'), cell(wrapper, COOLANT, 'tree-factories'), cell(wrapper, COOLANT, 'tree-produce-cost'), cell(wrapper, COOLANT, 'tree-price')]).toEqual(['240', '2', '1.64 K', '1.00 K'])
    expect([cell(wrapper, WATER, 'tree-per-day'), cell(wrapper, WATER, 'tree-factories'), cell(wrapper, WATER, 'tree-produce-cost'), cell(wrapper, WATER, 'tree-price')]).toEqual(['1,920', '-', '2.4', '60'])
    expect([cell(wrapper, AQUEOUS_LIQUIDS, 'tree-per-day'), cell(wrapper, AQUEOUS_LIQUIDS, 'tree-factories')]).toEqual(['288,000', '-'])
    expect(rowOf(wrapper, AQUEOUS_LIQUIDS).find('[data-testid="tree-price"]').exists()).toBe(false)
    expect(rowOf(wrapper, ROBOTICS).find('[data-choice]').exists()).toBe(false)
  })

  it('stars buy on a P2 that costs more to make than to buy and produce on one that costs less, and no star on the target or a P1', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, ROBOTICS)

    expect(rowOf(wrapper, COOLANT).get('[data-testid="tree-star"]').attributes('data-star')).toBe('buy')
    expect(rowOf(wrapper, COOLANT).get('[data-testid="tree-star"]').attributes('title')).toBe(en.pi.planner.tree.starBuy)
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tree-star"]').attributes('data-star')).toBe('produce')
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tree-star"]').attributes('title')).toBe(en.pi.planner.tree.starProduce)
    expect(rowOf(wrapper, ROBOTICS).find('[data-testid="tree-star"]').exists()).toBe(false)
    expect(rowOf(wrapper, WATER).find('[data-testid="tree-star"]').exists()).toBe(false)
  })

  it('shows an unknown price cell and no star on a product without price, while its siblings keep theirs', async () => {
    const wrapper = await mountTab(withSellPrices(PLANNER_CATALOG, { [COOLANT]: null }))

    await addProduction(wrapper, ROBOTICS)
    const coolantPrice = rowOf(wrapper, COOLANT).get('[data-testid="tree-price"]')

    expect(coolantPrice.attributes('data-state')).toBe('unknown')
    expect(coolantPrice.text()).toBe(en.pi.planner.unknown)
    expect(rowOf(wrapper, COOLANT).find('[data-testid="tree-star"]').exists()).toBe(false)
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tree-price"]').attributes('data-state')).toBe('known')
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tree-star"]').attributes('data-star')).toBe('produce')
    expect(wrapper.get('[data-testid="planner-production-profit"]').text()).toContain(en.pi.planner.unknown)
    expect(wrapper.get('[data-testid="planner-production-profit"]').text()).not.toMatch(/\d/)
  })

  it('switches every starred product to its cheapest option when the player applies the cheapest everywhere', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    expect(wrapper.get('[data-testid="planner-apply-cheapest"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-testid="planner-apply-cheapest"]').trigger('click')
    await flushPromises()

    expect(rowOf(wrapper, COOLANT).get('[aria-pressed="true"]').attributes('data-choice')).toBe('buy')
    expect(rowOf(wrapper, MECHANICAL_PARTS).get('[aria-pressed="true"]').attributes('data-choice')).toBe('produce')
    expect(rowOf(wrapper, WATER).get('[aria-pressed="true"]').attributes('data-choice')).toBe('buy')
    expect(summaryText(wrapper, 'summary-unit-cost')).toBe('8.43 K')
  })

  it('keeps the apply cheapest button disabled while no production is selected', async () => {
    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="planner-apply-cheapest"]').attributes('disabled')).toBeDefined()
  })

  it('shows the tree and the unit cost of the production the player selects', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    await addProduction(wrapper, COOLANT)

    expect(productionRows(wrapper).map(row => row.attributes('data-selected'))).toEqual(['false', 'true'])
    expect([rootTypeId(wrapper), summaryText(wrapper, 'summary-unit-cost')]).toEqual([COOLANT, '1.64 K'])

    await wrapper.get('[aria-label="Show the tree of Robotics"]').trigger('click')
    await flushPromises()

    expect(productionRows(wrapper).map(row => row.attributes('data-selected'))).toEqual(['true', 'false'])
    expect([rootTypeId(wrapper), summaryText(wrapper, 'summary-unit-cost')]).toEqual([ROBOTICS, '10.56 K'])
  })

  it('removes a production, moves the tree to the one that takes its place and shows the invitation again once none is left', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    await addProduction(wrapper, COOLANT)
    await wrapper.get('[aria-label="Show the tree of Robotics"]').trigger('click')

    await wrapper.get('[aria-label="Remove Robotics"]').trigger('click')
    await flushPromises()

    expect(productionRows(wrapper)).toHaveLength(1)
    expect(rootTypeId(wrapper)).toBe(COOLANT)

    await wrapper.get('[aria-label="Remove Coolant"]').trigger('click')
    await flushPromises()

    expect(productionRows(wrapper)).toHaveLength(0)
    expect(rows(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-testid="planner-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="planner-summary"]').exists()).toBe(false)
  })

  it('flags an invalid target in error and leaves the calculation unchanged until a valid one is typed', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    const input = wrapper.get('[data-testid="planner-target-input"]')

    for (const invalid of ['0', '-5', '']) {
      await input.setValue(invalid)
      await flushPromises()

      expect(input.attributes('aria-invalid')).toBe('true')
      expect([cell(wrapper, ROBOTICS, 'tree-per-day'), summaryText(wrapper, 'summary-unit-cost'), summaryText(wrapper, 'summary-profit')]).toEqual(['72', '10.56 K', '1.40 M'])
    }
    expect(wrapper.text()).toContain(en.pi.planner.productions.targetInvalid)

    await input.setValue('36')
    await flushPromises()

    expect(input.attributes('aria-invalid')).toBe('false')
    expect(cell(wrapper, ROBOTICS, 'tree-per-day')).toBe('36')
  })

  it('refuses a tax that is empty, negative or above 100 and recomputes the costs from a valid one', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    const input = wrapper.get('[data-testid="planner-tax-input"]')

    for (const invalid of ['150', '-1', '']) {
      await input.setValue(invalid)
      await flushPromises()

      expect(input.attributes('aria-invalid')).toBe('true')
      expect(summaryText(wrapper, 'summary-unit-cost')).toBe('10.56 K')
    }
    expect(wrapper.text()).toContain(en.pi.planner.settings.taxInvalid)

    await input.setValue('7.5')
    await flushPromises()

    expect(input.attributes('aria-invalid')).toBe('false')
    expect(summaryText(wrapper, 'summary-unit-cost')).toBe('11.98 K')
  })

  it('recomputes the summary and the prices of the tree from the Jita buy source', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)

    await wrapper.get('[data-source="jita-buy"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-source="jita-buy"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-source="jita-sell"]').attributes('aria-pressed')).toBe('false')
    expect(summaryText(wrapper, 'summary-unit-cost')).toBe('9.24 K')
    expect(summaryText(wrapper, 'summary-profit')).toBe('1.35 M')
    expect(summaryText(wrapper, 'summary-purchases')).toMatch(/(?<![\d.,])3\.90 M(?![\d.,])/)
    expect([cell(wrapper, COOLANT, 'tree-price'), cell(wrapper, WATER, 'tree-price')]).toEqual(['900', '50'])
  })
})
