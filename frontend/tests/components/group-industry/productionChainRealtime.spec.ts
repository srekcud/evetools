import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { TRITANIUM } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead-batch'
const OTHER_PROJECT_ID = 'project-drake-batch'
const CHAIN_PATH = `/industry/projects/${PROJECT_ID}/chain`

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function chainReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === CHAIN_PATH).length
}

function contributionSynced(): GroupProjectEvent {
  return { action: 'contribution_synced', data: null, timestamp: '2026-11-06T10:00:00+00:00' }
}

function linkedJobProgress(projectId: string) {
  return {
    syncType: 'industry-project',
    status: 'notification' as never,
    progress: null,
    message: 'Hammerhead I job delivered',
    data: { projectId },
    timestamp: '2026-11-06T10:05:00+00:00',
  }
}

async function mountChain() {
  vi.mocked(apiRequest).mockResolvedValue({ id: PROJECT_ID, nodes: [TRITANIUM] })
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  mount(ProductionChain, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return syncStore
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

describe('ProductionChain realtime refresh', () => {
  it('reads the chain again on an event of its project, whatever the action, and not on an event of another project', async () => {
    await mountChain()
    const readsAtMount = chainReads()

    projectEvents.value = { [OTHER_PROJECT_ID]: contributionSynced() }
    await flushPromises()
    const readsAfterOtherProject = chainReads()
    projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({ readsAtMount, readsAfterOtherProject, readsAfterOwnEvent: chainReads() }).toEqual({
      readsAtMount: 1,
      readsAfterOtherProject: 1,
      readsAfterOwnEvent: 2,
    })
  })

  it('reads the chain again when a job of its project is delivered and not when a job of another project is', async () => {
    const syncStore = await mountChain()

    syncStore.syncStatus['industry-project'] = linkedJobProgress(OTHER_PROJECT_ID)
    await flushPromises()
    const readsAfterOtherProject = chainReads()
    syncStore.syncStatus['industry-project'] = linkedJobProgress(PROJECT_ID)
    await flushPromises()

    expect({ readsAfterOtherProject, readsAfterOwnProject: chainReads() }).toEqual({
      readsAfterOtherProject: 1,
      readsAfterOwnProject: 2,
    })
  })
})
