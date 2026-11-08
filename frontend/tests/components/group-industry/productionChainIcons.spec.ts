import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import {
  chainNode,
  HAMMERHEAD_I,
  HAMMERHEAD_I_ID,
  PRODUCTION_CHAIN,
} from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const HAMMERHEAD_I_ICON_URL = 'https://images.evetech.net/types/2183/icon?size=32'
const UNTYPED_ID = 'node-without-type'
const UNTYPED_NODE = chainNode({
  id: UNTYPED_ID,
  stage: 'component',
  parentId: HAMMERHEAD_I.id,
  level: 1,
  activity: 'manufacturing',
  runs: 1,
  typeId: null,
  typeName: 'Unresolved material',
})
const CHAIN_WITH_UNTYPED_NODE: ChainNode[] = [...PRODUCTION_CHAIN, UNTYPED_NODE]

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

async function openTree(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProductionChain tree item icons', () => {
  it('shows the type icon in front of the name on a typed row', async () => {
    const wrapper = await mountChain(CHAIN_WITH_UNTYPED_NODE)
    await openTree(wrapper)

    const icon = wrapper.get(`[data-testid="chain-row-${HAMMERHEAD_I_ID}"] td img`)

    expect(icon.attributes('src')).toBe(HAMMERHEAD_I_ICON_URL)
  })

  it('keeps an empty slot of the icon size on a row without type id, with no icon', async () => {
    const wrapper = await mountChain(CHAIN_WITH_UNTYPED_NODE)
    await openTree(wrapper)

    const untypedRow = wrapper.get(`[data-testid="chain-row-${UNTYPED_ID}"]`)
    const placeholder = untypedRow.get('[data-testid="chain-icon-placeholder"]')

    expect(untypedRow.find('img').exists()).toBe(false)
    expect(placeholder.classes()).toEqual(expect.arrayContaining(['w-6', 'h-6']))
  })
})

describe('ProductionChain overview item icons', () => {
  it('keeps the type icon on a typed node', async () => {
    const wrapper = await mountChain(CHAIN_WITH_UNTYPED_NODE)

    const icon = wrapper.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"] img`)

    expect(icon.attributes('src')).toBe(HAMMERHEAD_I_ICON_URL)
  })

  it('keeps an empty slot of the icon size on a node without type id, with no icon', async () => {
    const wrapper = await mountChain(CHAIN_WITH_UNTYPED_NODE)

    const untypedNode = wrapper.get(`[data-testid="chain-node-${UNTYPED_ID}"]`)
    const placeholder = untypedNode.get('[data-testid="chain-icon-placeholder"]')

    expect(untypedNode.find('img').exists()).toBe(false)
    expect(placeholder.classes()).toEqual(expect.arrayContaining(['w-6', 'h-6']))
  })
})
