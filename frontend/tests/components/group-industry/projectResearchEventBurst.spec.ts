import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS } from '@/composables/useCoalescedGroupProjectEvents'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { HOBGOBLIN_II_WORTH, projectResearch } from '../../researchRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const RESEARCH_PATH = `/industry/projects/${PROJECT_ID}/research`

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function researchReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === RESEARCH_PATH).length
}

function publish(action: string): void {
  projectEvents.value = { [PROJECT_ID]: { action, data: null, timestamp: '2026-11-21T10:00:00+00:00' } }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ProjectResearch on a burst of realtime events', () => {
  it('reads the research once at the first event and once at the end of the window, however many events follow', async () => {
    vi.mocked(apiRequest).mockResolvedValue(projectResearch(PROJECT_ID, [HOBGOBLIN_II_WORTH]))
    setActivePinia(createPinia())
    vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
    mount(ProjectResearch, { props: { projectId: PROJECT_ID }, global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] } })
    await flushPromises()
    const readsAtMount = researchReads()

    for (const action of ['sale_recorded', 'sale_recorded', 'sale_updated', 'sale_recorded', 'sale_deleted']) {
      publish(action)
      await flushPromises()
    }
    const readsAfterBurst = researchReads()
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    await flushPromises()

    expect({ readsAtMount, readsAfterBurst, readsAfterWindow: researchReads() }).toEqual({
      readsAtMount: 1,
      readsAfterBurst: 2,
      readsAfterWindow: 3,
    })
  })
})
