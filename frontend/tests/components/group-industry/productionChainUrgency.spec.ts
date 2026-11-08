import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import { messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { chainNode, SALE, SALE_ID } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-urgency'

const COMPONENT_TO_LAUNCH = chainNode({ id: 'component-to-launch', stage: 'component', typeId: 1, typeName: 'Alpha part', activity: 'manufacturing', runs: 10, status: 'todo', level: 1 })
const COMPONENT_MISSING = chainNode({ id: 'component-missing', stage: 'component', typeId: 2, typeName: 'Bravo part', activity: 'manufacturing', runs: 10, status: 'missing_materials', level: 1 })
const COMPONENT_DONE = chainNode({ id: 'component-done', stage: 'component', typeId: 3, typeName: 'Charlie part', activity: 'manufacturing', runs: 10, status: 'done', level: 1 })
const COMPONENT_DONE_TOO = chainNode({ id: 'component-done-too', stage: 'component', typeId: 4, typeName: 'Delta part', activity: 'manufacturing', runs: 10, status: 'done', level: 2 })
const MATERIAL_TODO = chainNode({ id: 'material-todo', stage: 'material', typeId: 34, typeName: 'Tritanium', requiredQuantity: 100, fulfilledQuantity: 0, status: 'todo', level: 1 })
const MATERIAL_DONE = chainNode({ id: 'material-done', stage: 'material', typeId: 35, typeName: 'Pyerite', requiredQuantity: 100, fulfilledQuantity: 100, status: 'done', level: 1 })
const PRODUCT_TO_LAUNCH = chainNode({ id: 'product-to-launch', stage: 'product', typeId: 5, typeName: 'Omega ship', activity: 'manufacturing', runs: 1, status: 'todo', level: 2 })

function text(locale: TestLocale, key: string): string {
  return messageOf(locale, `groupIndustry.chain.${key}`)
}

async function mountChain(nodes: ChainNode[], locale: TestLocale = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async endpoint => (
    endpoint === `/industry/projects/${PROJECT_ID}/reactions` ? { id: PROJECT_ID, steps: [] } : { id: PROJECT_ID, nodes }
  ))
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function nodeIdsOutsideTheFold(wrapper: VueWrapper, column: string): string[] {
  return wrapper
    .findAll(`[data-testid="chain-column-${column}"] > [data-testid^="chain-node-"]`)
    .map(node => node.attributes('data-testid')!.replace('chain-node-', ''))
}

describe('production chain overview urgency', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('keeps the order of the API for the nodes that are not done', async () => {
    const wrapper = await mountChain([COMPONENT_TO_LAUNCH, COMPONENT_MISSING, COMPONENT_DONE])

    expect(nodeIdsOutsideTheFold(wrapper, 'components')).toEqual(['component-to-launch', 'component-missing'])
  })

  it('groups the done nodes of a column under a closed fold counting them', async () => {
    const wrapper = await mountChain([COMPONENT_TO_LAUNCH, COMPONENT_DONE, COMPONENT_DONE_TOO])

    const fold = wrapper.get('[data-testid="chain-done-components"]')
    expect(fold.element.tagName).toBe('DETAILS')
    expect(fold.attributes('open')).toBeUndefined()
    expect(fold.text()).toContain(text('en', 'flow.doneCount').replace('{count}', '2'))
    expect(fold.findAll('[data-testid^="chain-node-"]').map(node => node.attributes('data-testid'))).toEqual(['chain-node-component-done', 'chain-node-component-done-too'])
  })

  it('places the fold after the nodes that are not done', async () => {
    const wrapper = await mountChain([COMPONENT_DONE, COMPONENT_TO_LAUNCH])

    const children = wrapper.get('[data-testid="chain-column-components"]').element.children
    expect(children[children.length - 1].getAttribute('data-testid')).toBe('chain-done-components')
  })

  it('folds the done nodes of the invention and materials column and of the product column separately', async () => {
    const product = chainNode({ ...PRODUCT_TO_LAUNCH, id: 'product-done', status: 'done' })
    const wrapper = await mountChain([MATERIAL_TODO, MATERIAL_DONE, product, PRODUCT_TO_LAUNCH])

    expect(wrapper.get('[data-testid="chain-done-inventionMaterials"]').text()).toContain(text('en', 'flow.doneCount').replace('{count}', '1'))
    expect(wrapper.get('[data-testid="chain-done-product"]').text()).toContain(text('en', 'flow.doneCount').replace('{count}', '1'))
    expect(nodeIdsOutsideTheFold(wrapper, 'inventionMaterials')).toEqual(['material-todo'])
  })

  it('shows no fold in a column without done node', async () => {
    const wrapper = await mountChain([COMPONENT_TO_LAUNCH, COMPONENT_MISSING])

    expect(wrapper.find('[data-testid^="chain-done-"]').exists()).toBe(false)
  })

  it('never folds the sale node, even when the project is completed', async () => {
    const wrapper = await mountChain([COMPONENT_TO_LAUNCH, { ...SALE, status: 'done' }])

    expect(wrapper.find('[data-testid="chain-done-sale"]').exists()).toBe(false)
    expect(nodeIdsOutsideTheFold(wrapper, 'sale')).toEqual([SALE_ID])
  })

  it('opens the tree on the selected node when a folded node is clicked', async () => {
    const wrapper = await mountChain([COMPONENT_TO_LAUNCH, COMPONENT_DONE])

    await wrapper.get('[data-testid="chain-node-component-done"]').trigger('click')

    expect(wrapper.find('[data-testid="chain-flow"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chain-row-component-done"]').exists()).toBe(true)
  })

  it.each(TEST_LOCALES)('labels the missing materials status in %s on the node chip and in the legend', async (locale) => {
    const wrapper = await mountChain([COMPONENT_MISSING], locale)

    const label = text(locale, 'status.missing_materials')
    expect(wrapper.get('[data-testid="chain-node-component-missing"]').text()).toContain(label)
    expect(wrapper.get('footer').text()).toContain(label)
  })

  it('gives the missing materials status its own colour, distinct from blocked', async () => {
    const wrapper = await mountChain([COMPONENT_MISSING, chainNode({ ...COMPONENT_MISSING, id: 'component-blocked', status: 'blocked' })])

    const missingClass = wrapper.get('[data-testid="chain-node-component-missing"]').classes().join(' ')
    const blockedClass = wrapper.get('[data-testid="chain-node-component-blocked"]').classes().join(' ')
    expect(missingClass).not.toBe(blockedClass)
    expect(missingClass).toContain('border-l-orange-500')
  })
})
