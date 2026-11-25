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
  if (failure) {
    vi.mocked(apiRequest).mockRejectedValue(failure)
  } else {
    vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...catalog } as never)
  }
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

enableAutoUnmount(afterEach)

describe('PiPlannerTab', () => {
  it('loads the planner resource and invites the player to add a production while there is none', async () => {
    const wrapper = await mountTab()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/planner'])
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
})
