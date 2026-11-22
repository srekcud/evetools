import { enableAutoUnmount } from '@vue/test-utils'
import FactoryProjectStock from '@/components/industry-factory/FactoryProjectStock.vue'
import { useFactoryStore } from '@/stores/industry/factory'
import { PROJECT_STOCK, PROJECT_STOCK_URL } from '../../industryFactoryFixtures'
import { mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

const ROWS = '[data-testid="factory-project-stock-row"]'
const EMPTY = '[data-testid="factory-project-stock-empty"]'

describe('FactoryProjectStock', () => {
  it('shows each line with its item, quantity, project link and reserved, consumed or returned state', async () => {
    const { wrapper } = await mountFactory(FactoryProjectStock, {
      answers: { [`GET ${PROJECT_STOCK_URL}`]: PROJECT_STOCK },
      seed: () => {
        useFactoryStore().projectStock = PROJECT_STOCK
      },
    })

    const rows = wrapper.findAll(ROWS)
    expect(rows).toHaveLength(3)
    expect(rows.map(row => row.attributes('data-state'))).toEqual(['reserved', 'consumed', 'returned'])
    expect(rows[0].text()).toContain('Tritanium')
    expect(rows[0].text()).toContain('60')
    expect(rows[1].text()).toContain('Pyerite')
    expect(rows[1].text()).toContain('80')
    expect(rows[2].text()).toContain('15')
    expect(rows[0].get('a').attributes('href')).toBe('/industry/projects/rifter')
    expect(rows[0].get('a').text()).toBe('Rifter batch')
    expect(wrapper.find(EMPTY).exists()).toBe(false)
  })

  it('shows the empty message and no row when no stock is committed to a project', async () => {
    const { wrapper } = await mountFactory(FactoryProjectStock, {
      answers: { [`GET ${PROJECT_STOCK_URL}`]: [] },
      seed: () => {
        useFactoryStore().projectStock = []
      },
    })

    expect(wrapper.findAll(ROWS)).toHaveLength(0)
    expect(wrapper.find(EMPTY).exists()).toBe(true)
  })
})
