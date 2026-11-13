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
  HAMMERHEAD_I_COPY,
  HAMMERHEAD_I_COPY_ID,
  HAMMERHEAD_I_ID,
  HAMMERHEAD_II,
  HAMMERHEAD_II_ID,
  HAMMERHEAD_II_INVENTION,
  HAMMERHEAD_II_INVENTION_ID,
  PRODUCTION_CHAIN,
} from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const COPY_BUTTON = '[data-testid="planning-copy-blueprint-name"]'
const HAMMERHEAD_I_BLUEPRINT = 'Hammerhead I Blueprint'
const HAMMERHEAD_II_BLUEPRINT = 'Hammerhead II Blueprint'
const NOCXIUM_FORMULA = 'Nocxium Atoms Reaction Formula'
const REACTION_ID = 'node-nocxium-reaction'
const REACTION_NODE = chainNode({
  id: REACTION_ID,
  stage: 'component',
  parentId: HAMMERHEAD_I_ID,
  level: 0,
  typeId: 16_637,
  typeName: 'Nocxium Atoms',
  activity: 'reaction',
  runs: 3,
  blueprintName: NOCXIUM_FORMULA,
})

function chainWithBlueprintNames(): ChainNode[] {
  return [
    { ...HAMMERHEAD_II_INVENTION, blueprintName: null },
    { ...HAMMERHEAD_I_COPY, blueprintName: HAMMERHEAD_I_BLUEPRINT },
    REACTION_NODE,
    ...PRODUCTION_CHAIN.filter(node => node.id !== HAMMERHEAD_II_INVENTION_ID && node.id !== HAMMERHEAD_I_COPY_ID).map(node => (
      node.id === HAMMERHEAD_I_ID ? { ...HAMMERHEAD_I, blueprintName: HAMMERHEAD_I_BLUEPRINT }
        : node.id === HAMMERHEAD_II_ID ? { ...HAMMERHEAD_II, blueprintName: HAMMERHEAD_II_BLUEPRINT }
          : node
    )),
  ]
}

const writeText = vi.fn<(text: string) => Promise<void>>()

async function mountTree(nodes: ChainNode[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async endpoint => (
    endpoint === `/industry/projects/${PROJECT_ID}/reactions` ? { id: PROJECT_ID, steps: [] } : { id: PROJECT_ID, nodes }
  ))
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')
  return wrapper
}

function rowButton(wrapper: VueWrapper, nodeId: string) {
  return wrapper.find(`[data-testid="chain-row-${nodeId}"] ${COPY_BUTTON}`)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('ProductionChain job tree copy of the blueprint name', () => {
  it('copies the blueprint name of a manufacturing row, not the name of its product', async () => {
    const wrapper = await mountTree(chainWithBlueprintNames())

    await rowButton(wrapper, HAMMERHEAD_II_ID).trigger('click')

    expect(writeText.mock.calls).toEqual([[HAMMERHEAD_II_BLUEPRINT]])
  })

  it('copies the formula name of a reaction row and the blueprint name of a copy row', async () => {
    const wrapper = await mountTree(chainWithBlueprintNames())

    await rowButton(wrapper, REACTION_ID).trigger('click')
    await rowButton(wrapper, HAMMERHEAD_I_COPY_ID).trigger('click')

    expect(writeText.mock.calls).toEqual([[NOCXIUM_FORMULA], [HAMMERHEAD_I_BLUEPRINT]])
  })

  it('offers no button on a row whose blueprint name is unknown, rather than copying the product name', async () => {
    const wrapper = await mountTree(chainWithBlueprintNames())

    expect(wrapper.find(`[data-testid="chain-row-${HAMMERHEAD_II_INVENTION_ID}"]`).exists()).toBe(true)
    expect(rowButton(wrapper, HAMMERHEAD_II_INVENTION_ID).exists()).toBe(false)
    expect(rowButton(wrapper, HAMMERHEAD_II_ID).exists()).toBe(true)
  })

  it('confirms the copy on the row button once the clipboard accepted it', async () => {
    const wrapper = await mountTree(chainWithBlueprintNames())
    const button = rowButton(wrapper, HAMMERHEAD_II_ID)

    expect(button.attributes('data-state')).toBe('idle')
    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('data-state')).toBe('copied')
  })
})
