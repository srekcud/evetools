import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { CHAIN_STATUS_FILTER_STORAGE_KEY } from '@/components/group-industry/chainStatusFilter'
import { apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import type { TestLocale } from '../../localeTexts'
import { chainNode, HAMMERHEAD_I, HAMMERHEAD_I_COPY, HAMMERHEAD_I_COPY_ID, HAMMERHEAD_II_INVENTION, HAMMERHEAD_II_INVENTION_ID } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-materials-summary'
const CARD = '[data-testid="chain-materials-summary"]'

const TRITANIUM_LINE = chainNode({ id: 'material-tritanium', stage: 'material', typeId: 34, typeName: 'Tritanium', requiredQuantity: 100, fulfilledQuantity: 100, status: 'done' })
const PYERITE_LINE = chainNode({ id: 'material-pyerite', stage: 'material', typeId: 35, typeName: 'Pyerite', requiredQuantity: 100, fulfilledQuantity: 50, status: 'active' })
const MEXALLON_LINE = chainNode({ id: 'material-mexallon', stage: 'material', typeId: 36, typeName: 'Mexallon', requiredQuantity: 10, fulfilledQuantity: 10, status: 'done' })
const ISOGEN_LINE = chainNode({ id: 'material-isogen', stage: 'material', typeId: 37, typeName: 'Isogen', requiredQuantity: 100, fulfilledQuantity: 40, status: 'todo' })

function unknownFulfilled(node: ChainNode): ChainNode {
  return { ...node, fulfilledQuantity: null }
}

function unknownRequired(node: ChainNode): ChainNode {
  return { ...node, requiredQuantity: null }
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

function cardText(wrapper: VueWrapper): string {
  return wrapper.get(CARD).text().replace(/\s+/g, ' ')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ProductionChain materials summary card', () => {
  it('counts the material lines whose fulfilled quantity reaches the required quantity', async () => {
    const wrapper = await mountChain([HAMMERHEAD_II_INVENTION, TRITANIUM_LINE, PYERITE_LINE, MEXALLON_LINE])

    expect(cardText(wrapper)).toBe('2 / 3 material lines supplied')
    expect(cardText(wrapper)).not.toContain('unknown')
  })

  it('counts a line fulfilled above its required quantity as supplied', async () => {
    const wrapper = await mountChain([{ ...TRITANIUM_LINE, requiredQuantity: 100, fulfilledQuantity: 130 }, ISOGEN_LINE])

    expect(cardText(wrapper)).toBe('1 / 2 material lines supplied')
  })

  it('counts a line with an unknown fulfilled quantity apart, never as supplied', async () => {
    const wrapper = await mountChain([TRITANIUM_LINE, unknownFulfilled(ISOGEN_LINE)])

    expect(cardText(wrapper)).toContain('1 / 2 material lines supplied')
    expect(cardText(wrapper)).toContain('1 unknown')
  })

  it('counts a line with an unknown required quantity apart, never as supplied', async () => {
    const wrapper = await mountChain([unknownRequired(PYERITE_LINE), unknownFulfilled(ISOGEN_LINE), MEXALLON_LINE])

    expect(cardText(wrapper)).toContain('1 / 3 material lines supplied')
    expect(cardText(wrapper)).toContain('2 unknown')
  })

  it('shows no card when the chain has no material line, and keeps the blueprint nodes', async () => {
    const wrapper = await mountChain([HAMMERHEAD_II_INVENTION, HAMMERHEAD_I_COPY])

    expect(wrapper.find(CARD).exists()).toBe(false)
    expect(wrapper.find(`[data-testid="chain-node-${HAMMERHEAD_II_INVENTION_ID}"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-testid="chain-node-${HAMMERHEAD_I_COPY_ID}"]`).exists()).toBe(true)
  })

  it('sits in the invention and materials column as a button, next to the blueprint nodes, in place of the material nodes', async () => {
    const wrapper = await mountChain([TRITANIUM_LINE, HAMMERHEAD_II_INVENTION, PYERITE_LINE, HAMMERHEAD_I_COPY])

    const column = wrapper.get('[data-testid="chain-column-inventionMaterials"]')
    expect({
      tag: column.get(CARD).element.tagName,
      shownIds: column.findAll('[data-testid^="chain-node-"]').map(node => node.attributes('data-testid')),
      materialNodes: wrapper.findAll('[data-testid="chain-node-material-tritanium"], [data-testid="chain-node-material-pyerite"]').length,
    }).toEqual({
      tag: 'BUTTON',
      shownIds: [`chain-node-${HAMMERHEAD_II_INVENTION_ID}`, `chain-node-${HAMMERHEAD_I_COPY_ID}`],
      materialNodes: 0,
    })
  })

  it('asks the project to open the sourcing tab on click, without leaving the overview', async () => {
    const wrapper = await mountChain([TRITANIUM_LINE, PYERITE_LINE])

    await wrapper.get(CARD).trigger('click')

    expect({
      openSourcing: wrapper.emitted('open-sourcing')?.length,
      overviewStillShown: wrapper.find('[data-testid="chain-flow"]').exists(),
      treeShown: wrapper.find('[data-testid="chain-tree"]').exists(),
    }).toEqual({ openSourcing: 1, overviewStillShown: true, treeShown: false })
  })

  it('ignores the status filter, which is meant for the jobs', async () => {
    localStorage.setItem(CHAIN_STATUS_FILTER_STORAGE_KEY, JSON.stringify(['blocked']))
    const wrapper = await mountChain([HAMMERHEAD_I, TRITANIUM_LINE, PYERITE_LINE, MEXALLON_LINE])

    const overview = { card: cardText(wrapper), jobNodes: wrapper.findAll('[data-testid^="chain-node-"]').length }
    await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')

    expect({ overview, treeEmptyFilter: wrapper.find('[data-testid="chain-filter-empty"]').exists() }).toEqual({
      overview: { card: '2 / 3 material lines supplied', jobNodes: 0 },
      treeEmptyFilter: true,
    })
  })

  it('shows no material row in the job tree', async () => {
    const wrapper = await mountChain([HAMMERHEAD_I, TRITANIUM_LINE, PYERITE_LINE])

    await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')

    expect(wrapper.findAll('[data-testid^="chain-row-"]').map(row => row.attributes('data-testid'))).toEqual(['chain-row-node-hammerhead-i'])
  })

  it('reads in French without a raw message key', async () => {
    const wrapper = await mountChain([TRITANIUM_LINE, unknownFulfilled(ISOGEN_LINE)], 'fr')

    const text = cardText(wrapper)
    expect({
      rawKey: text.includes('groupIndustry.'),
      english: text.includes('material lines supplied') || text.includes('unknown'),
      counts: text.includes('1') && text.includes('2'),
    }).toEqual({ rawKey: false, english: false, counts: true })
  })
})
