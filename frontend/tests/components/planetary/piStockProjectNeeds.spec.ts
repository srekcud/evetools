import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockProjectNeeds from '@/components/planetary/PiStockProjectNeeds.vue'
import { usePlanetaryProjectNeedsStore, type ProjectNeedProduct } from '@/stores/planetaryProjectNeeds'
import {
  BROADCAST_NODE_NEED,
  ISHTAR_FLEET_ID,
  RATTLESNAKE_BATCH_ID,
  ROBOTICS_NEED,
  SLEEPER_BATCH_ID,
  UNKNOWN_STOCK_NEED,
} from '../../planetaryProjectNeedsFixtures'

function mountBlock(products: ProjectNeedProduct[], error: string | null = null) {
  setActivePinia(createPinia())
  const store = usePlanetaryProjectNeedsStore()
  store.products = products
  store.error = error
  return mount(PiStockProjectNeeds, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
}

function digitsOf(text: string): string {
  return text.replace(/[^0-9]/g, '')
}

function cell(wrapper: ReturnType<typeof mountBlock>, field: string, typeId: number) {
  return wrapper.get(`[data-testid="project-need-cell-${field}-${typeId}"]`)
}

function projectLink(wrapper: ReturnType<typeof mountBlock>, typeId: number, projectId: string) {
  const testId = `project-need-project-${typeId}-${projectId}`
  const link = wrapper.findAllComponents(RouterLinkStub).find(candidate => candidate.attributes('data-testid') === testId)
  if (!link) throw new Error(`No project link ${testId}`)
  return link
}

enableAutoUnmount(afterEach)

describe('PiStockProjectNeeds', () => {
  it('shows one row per product in the order received with its name, the asked, in stock and missing quantities, and neither the empty message nor the error', async () => {
    const wrapper = mountBlock([BROADCAST_NODE_NEED, ROBOTICS_NEED, UNKNOWN_STOCK_NEED])
    await flushPromises()

    expect(wrapper.get('[data-testid="pi-stock-project-needs"]').text()).toContain(en.pi.stock.projectNeeds.title)
    expect(wrapper.findAll('[data-testid^="project-need-row-"]').map(row => row.attributes('data-testid'))).toEqual([
      `project-need-row-${BROADCAST_NODE_NEED.typeId}`,
      `project-need-row-${ROBOTICS_NEED.typeId}`,
      `project-need-row-${UNKNOWN_STOCK_NEED.typeId}`,
    ])
    expect(wrapper.get(`[data-testid="project-need-row-${BROADCAST_NODE_NEED.typeId}"]`).text()).toContain('Broadcast Node')
    expect(wrapper.get(`[data-testid="project-need-row-${ROBOTICS_NEED.typeId}"]`).text()).toContain('Robotics')
    expect(wrapper.get(`[data-testid="project-need-row-${UNKNOWN_STOCK_NEED.typeId}"]`).text()).toContain('Coolant')
    expect(['required', 'stock', 'missing'].map(field => digitsOf(cell(wrapper, field, BROADCAST_NODE_NEED.typeId).text()))).toEqual(['11', '7', '4'])
    expect(['required', 'stock', 'missing'].map(field => digitsOf(cell(wrapper, field, ROBOTICS_NEED.typeId).text()))).toEqual(['1200', '1200', '0'])
    expect(wrapper.find('[data-testid="project-needs-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="project-needs-error"]').exists()).toBe(false)
  })

  it('renders an unknown stock and an unknown missing quantity as unknown values while the asked quantity and the known cells stay figures', async () => {
    const wrapper = mountBlock([BROADCAST_NODE_NEED, UNKNOWN_STOCK_NEED])
    await flushPromises()

    expect(cell(wrapper, 'stock', UNKNOWN_STOCK_NEED.typeId).findComponent(UnknownValue).exists()).toBe(true)
    expect(cell(wrapper, 'missing', UNKNOWN_STOCK_NEED.typeId).findComponent(UnknownValue).exists()).toBe(true)
    expect(cell(wrapper, 'required', UNKNOWN_STOCK_NEED.typeId).findComponent(UnknownValue).exists()).toBe(false)
    expect(digitsOf(cell(wrapper, 'required', UNKNOWN_STOCK_NEED.typeId).text())).toBe('8')
    expect(cell(wrapper, 'stock', BROADCAST_NODE_NEED.typeId).findComponent(UnknownValue).exists()).toBe(false)
    expect(cell(wrapper, 'missing', BROADCAST_NODE_NEED.typeId).findComponent(UnknownValue).exists()).toBe(false)
  })

  it('links each project of a product to its page with its name, in the order received', async () => {
    const wrapper = mountBlock([BROADCAST_NODE_NEED, UNKNOWN_STOCK_NEED])
    await flushPromises()

    const ishtar = projectLink(wrapper, BROADCAST_NODE_NEED.typeId, ISHTAR_FLEET_ID)
    const rattlesnake = projectLink(wrapper, BROADCAST_NODE_NEED.typeId, RATTLESNAKE_BATCH_ID)
    const sleeper = projectLink(wrapper, UNKNOWN_STOCK_NEED.typeId, SLEEPER_BATCH_ID)
    expect(ishtar.props('to')).toEqual({ name: 'industry-project', params: { id: ISHTAR_FLEET_ID } })
    expect(ishtar.text()).toBe('Ishtar fleet')
    expect(rattlesnake.props('to')).toEqual({ name: 'industry-project', params: { id: RATTLESNAKE_BATCH_ID } })
    expect(rattlesnake.text()).toBe('Rattlesnake batch')
    expect(sleeper.props('to')).toEqual({ name: 'industry-project', params: { id: SLEEPER_BATCH_ID } })
    expect(sleeper.text()).toBe('Sleeper batch')
    expect(wrapper.findAll('[data-testid^="project-need-project-"]')).toHaveLength(3)
    expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(3)
  })

  it('shows the empty message and no row when no project asks for a product', async () => {
    const wrapper = mountBlock([])
    await flushPromises()

    expect(wrapper.get('[data-testid="project-needs-empty"]').text()).toBe(en.pi.stock.projectNeeds.empty)
    expect(wrapper.findAll('[data-testid^="project-need-row-"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="project-needs-error"]').exists()).toBe(false)
  })

  it('shows the load error without any row nor the empty message when the products could not be read', async () => {
    const wrapper = mountBlock([], en.pi.stock.projectNeeds.loadError)
    await flushPromises()

    expect(wrapper.get('[data-testid="project-needs-error"]').text()).toBe(en.pi.stock.projectNeeds.loadError)
    expect(wrapper.findAll('[data-testid^="project-need-row-"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="project-needs-empty"]').exists()).toBe(false)
  })
})
