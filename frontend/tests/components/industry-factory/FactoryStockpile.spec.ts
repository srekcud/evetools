import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FactoryStockpile from '@/components/industry-factory/FactoryStockpile.vue'
import { useIndustryStore } from '@/stores/industry'
import { useStockpileStore } from '@/stores/industry/stockpile'
import { ISHTAR_TARGET, STOCKPILE_STATUS, STOCKPILE_STATUS_URL } from '../../industryFactoryFixtures'
import { mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

const ROWS = '[data-testid="factory-stockpile-row"]'
const CREATE_PROJECT = '[data-testid="factory-create-project"]'

async function mountStockpile() {
  return mountFactory(FactoryStockpile, {
    answers: { [`GET ${STOCKPILE_STATUS_URL}`]: STOCKPILE_STATUS },
    seed: () => {
      useStockpileStore().stockpileStatus = STOCKPILE_STATUS
    },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
})

describe('FactoryStockpile', () => {
  it('lists one row per final product target with its level and best supplied station, and offers Create a project only with suggested runs', async () => {
    const { wrapper } = await mountStockpile()

    const rows = wrapper.findAll(ROWS)
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Ishtar')
    expect(rows[0].text()).toContain('12')
    expect(rows[0].text()).toContain('50')
    expect(rows[0].text()).toContain('Jita IV - Moon 4')
    expect(rows[1].text()).toContain('Rifter')
    expect(wrapper.text()).not.toContain('Tritanium')
    expect(rows[0].findAll(CREATE_PROJECT)).toHaveLength(1)
    expect(rows[1].findAll(CREATE_PROJECT)).toHaveLength(0)
  })

  it('sets the project intent with the suggested runs and goes to the projects page', async () => {
    const { wrapper, router } = await mountStockpile()
    const rows = wrapper.findAll(ROWS)
    expect(rows).toHaveLength(2)

    await rows[0].get(CREATE_PROJECT).trigger('click')
    await flushPromises()

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefill: { typeId: ISHTAR_TARGET.typeId, typeName: 'Ishtar', runs: 4 },
    })
    expect(router.currentRoute.value.name).toBe('industry-projects')
  })
})
