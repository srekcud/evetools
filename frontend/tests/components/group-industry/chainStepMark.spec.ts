import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ChainStepMark from '@/components/group-industry/ChainStepMark.vue'
import ProductionChainJobTree from '@/components/group-industry/ProductionChainJobTree.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { ChainNode } from '@/stores/group-industry/types'
import { expectedMessage } from '../../expectedMessage'
import type { TestLocale } from '../../localeTexts'
import {
  chainNode,
  HAMMERHEAD_I,
  HAMMERHEAD_I_COPY,
  HAMMERHEAD_I_ID,
  SALE,
  SALE_ID,
  TRITANIUM,
  TRITANIUM_ID,
} from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const COMPONENT_RUNS = 6
const MARK_KEYS = ['title', 'launched', 'done', 'runs', 'submit', 'remove', 'badge', 'markedLaunched', 'markedDone']

const COMPONENT: ChainNode = chainNode({ ...HAMMERHEAD_I, runs: COMPONENT_RUNS })

function markedText(locale: TestLocale, key: 'markedLaunched' | 'markedDone', runs: number): string {
  return expectedMessage(locale, `groupIndustry.chain.mark.${key}`).replace('{runs}', String(runs))
}

function mountMark(node: ChainNode, canMark: boolean, locale: TestLocale = 'en'): VueWrapper {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ChainStepMark, { props: { projectId: PROJECT_ID, node, canMark }, global: { plugins: [i18n] } })
}

function testIdsOf(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="chain-step-mark-"]').map(found => found.attributes('data-testid') ?? '')
}

function markPath(lineId: string): string {
  return `/industry/projects/${PROJECT_ID}/bom/${lineId}/mark`
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ChainStepMark', () => {
  it('shows the badge of a mark done to a member and gives him neither the form nor the removal', () => {
    const wrapper = mountMark({ ...COMPONENT, mark: { state: 'done', runs: 4 } }, false)

    expect(testIdsOf(wrapper)).toEqual(['chain-step-mark-badge'])
    expect(wrapper.get('[data-testid="chain-step-mark-badge"]').text()).toContain(markedText('en', 'markedDone', 4))
  })

  it('shows the badge of a mark launched in French', () => {
    const wrapper = mountMark({ ...COMPONENT, mark: { state: 'launched', runs: 5 } }, false, 'fr')

    expect(testIdsOf(wrapper)).toEqual(['chain-step-mark-badge'])
    expect(wrapper.get('[data-testid="chain-step-mark-badge"]').text()).toContain(markedText('fr', 'markedLaunched', 5))
  })

  it('offers the owner the form for a step without mark with the runs of the step as initial value between one and the runs of the step', () => {
    const wrapper = mountMark(COMPONENT, true)

    expect(testIdsOf(wrapper)).toEqual([
      'chain-step-mark-form',
      'chain-step-mark-state',
      'chain-step-mark-runs',
      'chain-step-mark-submit',
    ])
    const runs = wrapper.get('[data-testid="chain-step-mark-runs"]')
    expect([(runs.element as HTMLInputElement).value, runs.attributes('min'), runs.attributes('max')]).toEqual([String(COMPONENT_RUNS), '1', String(COMPONENT_RUNS)])
    expect(wrapper.findAll('[data-testid="chain-step-mark-state"] option').map(option => (option.element as HTMLOptionElement).value)).toEqual(['launched', 'done'])
  })

  it('posts the chosen state and runs of the owner then emits marked once the chain is read again', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ id: HAMMERHEAD_I_ID }).mockResolvedValueOnce({ id: PROJECT_ID, nodes: [COMPONENT] })
    const wrapper = mountMark(COMPONENT, true)

    expect(testIdsOf(wrapper)).toContain('chain-step-mark-form')
    await wrapper.get('[data-testid="chain-step-mark-state"]').setValue('done')
    await wrapper.get('[data-testid="chain-step-mark-runs"]').setValue(4)
    await wrapper.get('[data-testid="chain-step-mark-form"]').trigger('submit')
    await flushPromises()

    expect(apiRequest).toHaveBeenNthCalledWith(1, markPath(HAMMERHEAD_I_ID), { method: 'POST', body: JSON.stringify({ state: 'done', runs: 4 }) })
    expect(wrapper.emitted('marked')).toHaveLength(1)
  })

  it('removes the mark of the owner with a delete then emits marked', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(undefined).mockResolvedValueOnce({ id: PROJECT_ID, nodes: [COMPONENT] })
    const wrapper = mountMark({ ...COMPONENT, mark: { state: 'launched', runs: 3 } }, true)

    expect(testIdsOf(wrapper)).toContain('chain-step-mark-remove')
    await wrapper.get('[data-testid="chain-step-mark-remove"]').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenNthCalledWith(1, markPath(HAMMERHEAD_I_ID), { method: 'DELETE' })
    expect(wrapper.emitted('marked')).toHaveLength(1)
  })

  it('shows the message of the server when the mark is refused and emits nothing', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new ApiError('Runs must be between 1 and 6', 400))
    const wrapper = mountMark(COMPONENT, true)

    expect(testIdsOf(wrapper)).toContain('chain-step-mark-form')
    await wrapper.get('[data-testid="chain-step-mark-form"]').trigger('submit')
    await flushPromises()

    expect(testIdsOf(wrapper)).toContain('chain-step-mark-error')
    expect(wrapper.get('[data-testid="chain-step-mark-error"]').text()).toBe('Runs must be between 1 and 6')
    expect(wrapper.emitted('marked')).toBeUndefined()
    expect(apiRequest).toHaveBeenCalledOnce()
  })

  it('has the texts of the mark in both languages', () => {
    const missing = (['en', 'fr'] as const).flatMap(locale => MARK_KEYS.filter(key => expectedMessage(locale, `groupIndustry.chain.mark.${key}`).startsWith('<missing')).map(key => `${locale}.${key}`))

    expect(missing).toEqual([])
  })
})

describe('ProductionChainJobTree step mark', () => {
  it('mounts the step mark on the job nodes only', () => {
    setActivePinia(createPinia())
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const nodes = [TRITANIUM, { ...HAMMERHEAD_I_COPY, runs: 20 }, SALE]
    const wrapper = mount(ProductionChainJobTree, { props: { nodes, selectedId: null, projectId: PROJECT_ID, canMark: true }, global: { plugins: [i18n] } })

    const rowsWithForm = wrapper.findAll('[data-testid^="chain-row-"]').filter(found => found.find('[data-testid="chain-step-mark-form"]').exists()).map(found => found.attributes('data-testid'))

    expect(rowsWithForm).toEqual([`chain-row-${HAMMERHEAD_I_COPY.id}`])
    expect([TRITANIUM_ID, SALE_ID].filter(id => wrapper.find(`[data-testid="chain-row-${id}"]`).exists())).toEqual([])
  })
})
