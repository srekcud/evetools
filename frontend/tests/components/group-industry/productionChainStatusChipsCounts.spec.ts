import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import { chainNode } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-status-chips'

const ACTIVE_COMPONENT = chainNode({ id: 'component-active', stage: 'component', level: 1, typeId: 100, typeName: 'Caesarium Cadmide', activity: 'reaction', status: 'active' })
const TODO_PRODUCT = chainNode({ id: 'product-todo', stage: 'product', level: 2, typeId: 101, typeName: 'Hammerhead II', activity: 'manufacturing', status: 'todo' })
const PARTLY_SUPPLIED_MATERIAL = chainNode({ id: 'material-pyerite', stage: 'material', typeId: 35, typeName: 'Pyerite', requiredQuantity: 100, fulfilledQuantity: 50, status: 'active' })
const FULLY_SUPPLIED_MATERIAL = chainNode({ id: 'material-tritanium', stage: 'material', typeId: 34, typeName: 'Tritanium', requiredQuantity: 100, fulfilledQuantity: 100, status: 'done' })
const MISSING_MATERIAL = chainNode({ id: 'material-isogen', stage: 'material', typeId: 37, typeName: 'Isogen', requiredQuantity: 100, fulfilledQuantity: 0, status: 'missing_materials' })

async function mountChain(nodes: ChainNode[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async endpoint => (
    endpoint === `/industry/projects/${PROJECT_ID}/reactions` ? { id: PROJECT_ID, steps: [] } : { id: PROJECT_ID, nodes }
  ))
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function chipText(wrapper: VueWrapper, status: string): string {
  return wrapper.get(`[data-testid="chain-status-filter-${status}"]`).text().replace(/\s+/g, ' ')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ProductionChain status chips', () => {
  const NODES = [ACTIVE_COMPONENT, PARTLY_SUPPLIED_MATERIAL, FULLY_SUPPLIED_MATERIAL]

  it('counts only the steps the Overview lists, leaving the material lines out', async () => {
    const wrapper = await mountChain(NODES)

    expect(chipText(wrapper, 'active')).toContain('In progress 1')
    expect(wrapper.find('[data-testid="chain-status-filter-done"]').exists()).toBe(false)
  })

  it('offers no chip for a status only material lines have', async () => {
    const wrapper = await mountChain([ACTIVE_COMPONENT, MISSING_MATERIAL])

    expect(wrapper.find('[data-testid="chain-status-filter-missing_materials"]').exists()).toBe(false)
  })

  it('counts only the steps the job tree lists when the tree view is open', async () => {
    const wrapper = await mountChain(NODES)
    await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')

    expect(chipText(wrapper, 'active')).toContain('In progress 1')
    expect(wrapper.find('[data-testid="chain-status-filter-done"]').exists()).toBe(false)
  })

  it('keeps the chip counts of the listed steps unchanged', async () => {
    const wrapper = await mountChain([ACTIVE_COMPONENT, TODO_PRODUCT, PARTLY_SUPPLIED_MATERIAL])

    expect(chipText(wrapper, 'active')).toContain('In progress 1')
    expect(chipText(wrapper, 'todo')).toContain('To do 1')
  })
})
