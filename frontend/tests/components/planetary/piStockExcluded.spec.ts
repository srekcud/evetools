import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockExcluded from '@/components/planetary/PiStockExcluded.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { COOLANT, LONG_TYPE_NAME, PLANNER_CATALOG, ROBOTICS, WATER, withTypeName } from '../../planetaryPlannerFixtures'
import { SETTINGS_WITH_AMARR_HOME, patchCalls, serve } from '../../planetarySettingsFixtures'
import { PLASMA_ROW, stockOf } from '../../planetaryStockFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OUTSIDE_THE_CATALOG = 99_123

function mountExcluded(excludedTypeIds: number[], catalog = PLANNER_CATALOG) {
  setActivePinia(createPinia())
  serve({ 'PATCH /planetary/settings': (body) => ({ ...SETTINGS_WITH_AMARR_HOME, ...body }) })
  vi.mocked(apiRequest).mockClear()
  usePlanetaryStockStore().stock = stockOf([PLASMA_ROW], { excludedTypeIds })
  usePlanetarySettingsStore().settings = { ...SETTINGS_WITH_AMARR_HOME, excludedTypeIds }
  usePlanetaryPlannerStore().catalog = catalog
  return mount(PiStockExcluded, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

describe('PiStockExcluded', () => {
  it('is absent when no type is excluded', () => {
    const wrapper = mountExcluded([])

    expect(wrapper.find('[data-testid="stock-excluded"]').exists()).toBe(false)
  })

  it('shows the number of excluded types and one chip per type with its planner name, or the identifier when the planner does not know it', () => {
    const wrapper = mountExcluded([COOLANT, ROBOTICS, OUTSIDE_THE_CATALOG])

    const block = wrapper.get('[data-testid="stock-excluded"]')
    expect(block.text()).toContain('3')
    expect(wrapper.get(`[data-testid="stock-excluded-${COOLANT}"]`).text()).toContain('Coolant')
    expect(wrapper.get(`[data-testid="stock-excluded-${ROBOTICS}"]`).text()).toContain('Robotics')
    expect(wrapper.get(`[data-testid="stock-excluded-${OUTSIDE_THE_CATALOG}"]`).text()).toContain(`#${OUTSIDE_THE_CATALOG}`)
    expect(wrapper.findAll('[data-testid^="stock-restore-"]').map(button => button.attributes('data-testid'))).toEqual([
      `stock-restore-${COOLANT}`,
      `stock-restore-${ROBOTICS}`,
      `stock-restore-${OUTSIDE_THE_CATALOG}`,
    ])
  })

  it('restores the clicked type alone by patching the list of the types that stay excluded', async () => {
    const wrapper = mountExcluded([COOLANT, WATER])

    await wrapper.get(`[data-testid="stock-restore-${COOLANT}"]`).trigger('click')
    await flushPromises()

    expect(patchCalls()).toEqual([{ method: 'PATCH', endpoint: '/planetary/settings', body: { excludedTypeIds: [WATER] } }])
  })

  it('shows a very long type name whole in its chip next to its restore button', () => {
    const wrapper = mountExcluded([COOLANT, WATER], withTypeName(PLANNER_CATALOG, COOLANT, LONG_TYPE_NAME))

    const chip = wrapper.get(`[data-testid="stock-excluded-${COOLANT}"]`)
    expect(LONG_TYPE_NAME.length).toBeGreaterThan(80)
    expect(chip.text()).toContain(LONG_TYPE_NAME)
    expect(chip.find(`[data-testid="stock-restore-${COOLANT}"]`).exists()).toBe(true)
    expect(wrapper.get(`[data-testid="stock-excluded-${WATER}"]`).text()).toContain('Water')
  })
})
