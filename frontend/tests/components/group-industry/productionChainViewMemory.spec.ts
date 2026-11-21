import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { HAMMERHEAD_I_ID, HAMMERHEAD_II_ID, PRODUCTION_CHAIN } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const FLOW = '[data-testid="chain-flow"]'
const TREE = '[data-testid="chain-tree"]'
const SELECTED_ROW = '[data-testid^="chain-row-"][aria-selected="true"]'

type FocusRequest = { nodeId: string | null }

const scrollIntoView = vi.fn()

async function mountChain(focusRequest?: FocusRequest): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async endpoint => (
    endpoint === `/industry/projects/${PROJECT_ID}/reactions` ? { id: PROJECT_ID, steps: [] } : { id: PROJECT_ID, nodes: PRODUCTION_CHAIN }
  ))
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProductionChain, {
    props: { projectId: PROJECT_ID, ...(focusRequest === undefined ? {} : { focusRequest }) },
    global: { plugins: [i18n] },
  })
  await flushPromises()
  return wrapper
}

function shownView(wrapper: VueWrapper): 'flow' | 'tree' | 'none' {
  if (wrapper.find(TREE).exists()) return 'tree'
  return wrapper.find(FLOW).exists() ? 'flow' : 'none'
}

function selectedRows(wrapper: VueWrapper): (string | undefined)[] {
  return wrapper.findAll(SELECTED_ROW).map(selected => selected.attributes('data-testid'))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
  scrollIntoView.mockReset()
  Element.prototype.scrollIntoView = scrollIntoView
})

describe('ProductionChain opened on a focus request', () => {
  it('shows the job tree with the targeted step selected and scrolled into view', async () => {
    const wrapper = await mountChain({ nodeId: HAMMERHEAD_I_ID })

    expect({ view: shownView(wrapper), selected: selectedRows(wrapper) }).toEqual({
      view: 'tree',
      selected: [`chain-row-${HAMMERHEAD_I_ID}`],
    })
    expect(scrollIntoView.mock.contexts).toEqual([wrapper.get(`[data-testid="chain-row-${HAMMERHEAD_I_ID}"]`).element])
  })

  it('shows the job tree without any selected step when the request targets no step', async () => {
    const wrapper = await mountChain({ nodeId: null })

    expect({ view: shownView(wrapper), selected: selectedRows(wrapper) }).toEqual({ view: 'tree', selected: [] })
  })

  it('switches the overview to the job tree on the targeted step when a request arrives later', async () => {
    const wrapper = await mountChain()
    const viewBefore = shownView(wrapper)

    await wrapper.setProps({ focusRequest: { nodeId: HAMMERHEAD_II_ID } })
    await flushPromises()

    expect({ viewBefore, viewAfter: shownView(wrapper), selected: selectedRows(wrapper) }).toEqual({
      viewBefore: 'flow',
      viewAfter: 'tree',
      selected: [`chain-row-${HAMMERHEAD_II_ID}`],
    })
  })
})

describe('ProductionChain remembered view', () => {
  it('opens on the overview when nothing was chosen yet', async () => {
    const wrapper = await mountChain()

    expect(shownView(wrapper)).toBe('flow')
  })

  it('opens on the job tree after the tree was chosen, then back on the overview once the overview is chosen again', async () => {
    const first = await mountChain()
    await first.get('[data-testid="chain-view-tree"]').trigger('click')
    first.unmount()

    const second = await mountChain()
    const afterTreeChosen = shownView(second)
    await second.get('[data-testid="chain-view-flow"]').trigger('click')
    second.unmount()

    const third = await mountChain()

    expect({ afterTreeChosen, afterFlowChosen: shownView(third) }).toEqual({ afterTreeChosen: 'tree', afterFlowChosen: 'flow' })
  })

  it('remembers the job tree reached through a focus request', async () => {
    const first = await mountChain({ nodeId: HAMMERHEAD_I_ID })
    first.unmount()

    const second = await mountChain()

    expect(shownView(second)).toBe('tree')
  })

  it('remembers the job tree reached by clicking a step of the overview', async () => {
    const first = await mountChain()
    await first.get(`[data-testid="chain-node-${HAMMERHEAD_I_ID}"]`).trigger('click')
    first.unmount()

    const second = await mountChain()

    expect(shownView(second)).toBe('tree')
  })

  it('falls back to the overview when the remembered value is not a known view', async () => {
    localStorage.setItem('groupIndustry.chainView', 'sankey')

    const wrapper = await mountChain()

    expect(shownView(wrapper)).toBe('flow')
  })
})
