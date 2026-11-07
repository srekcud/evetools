import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import type { ChainNode, ReactionStep } from '@/stores/group-industry/types'
import { PRODUCTION_CHAIN, TRITANIUM } from '../../productionChainNodes'
import { FULLERIDES, FULLERIDES_ID, HYPERSYNAPTIC_FIBERS, HYPERSYNAPTIC_FIBERS_ID } from '../../reactionStepFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const OTHER_PROJECT_ID = 'project-drake-batch'
const CHAIN_PATH = `/industry/projects/${PROJECT_ID}/chain`
const REACTIONS_PATH = `/industry/projects/${PROJECT_ID}/reactions`

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function answerWith(nodes: ChainNode[], steps: ReactionStep[]): void {
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === CHAIN_PATH) return { id: PROJECT_ID, nodes }
    if (endpoint === REACTIONS_PATH) return { id: PROJECT_ID, steps }
    throw new Error(`unexpected request ${endpoint}`)
  })
}

async function mountChain(nodes: ChainNode[], steps: ReactionStep[]): Promise<VueWrapper> {
  answerWith(nodes, steps)
  setActivePinia(createPinia())
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const wrapper = mount(ProductionChain, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function reactionReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === REACTIONS_PATH).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

describe('ProductionChain reaction steps', () => {
  it('shows the reaction steps card under the chain when the chain has nodes and the project has reaction steps', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN, [FULLERIDES, HYPERSYNAPTIC_FIBERS])

    const rows = wrapper.findAll('[data-testid="reaction-step-row"]').map(row => Number(row.attributes('data-type-id')))

    expect(wrapper.find('[data-testid="reaction-steps"]').exists()).toBe(true)
    expect(rows).toEqual([FULLERIDES_ID, HYPERSYNAPTIC_FIBERS_ID])
    expect(apiRequest).toHaveBeenCalledWith(REACTIONS_PATH)
  })

  it('does not show the reaction steps card when the project has no reaction step', async () => {
    const wrapper = await mountChain(PRODUCTION_CHAIN, [])

    expect(wrapper.find('[data-testid="chain-view-flow"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="reaction-steps"]').exists()).toBe(false)
  })

  it('does not show the reaction steps card when the chain has no node', async () => {
    const wrapper = await mountChain([], [FULLERIDES])

    expect(wrapper.find('[data-testid="reaction-steps"]').exists()).toBe(false)
  })

  it('reads the reaction steps again on an event of its project and not on an event of another project', async () => {
    await mountChain([TRITANIUM], [FULLERIDES])
    const readsAtMount = reactionReads()

    projectEvents.value = { [OTHER_PROJECT_ID]: { action: 'contribution_synced', data: null, timestamp: '2026-11-06T10:00:00+00:00' } }
    await flushPromises()
    const readsAfterOtherProject = reactionReads()
    projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: { action: 'contribution_synced', data: null, timestamp: '2026-11-06T10:01:00+00:00' } }
    await flushPromises()

    expect({ readsAtMount, readsAfterOtherProject, readsAfterOwnEvent: reactionReads() }).toEqual({
      readsAtMount: 1,
      readsAfterOtherProject: 1,
      readsAfterOwnEvent: 2,
    })
  })
})
