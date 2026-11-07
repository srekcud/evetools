import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectPlanning from '@/components/group-industry/ProjectPlanning.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { LATE_PLANNING, PLANNING_PATH, PLANNING_PROJECT_ID } from '../../projectPlanningData'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OTHER_PROJECT_ID = 'project-drake-batch'

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function planningReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === PLANNING_PATH).length
}

function contributionSynced(): GroupProjectEvent {
  return { action: 'contribution_synced', data: null, timestamp: '2026-11-06T10:00:00+00:00' }
}

function linkedJobProgress(projectId: string) {
  return {
    syncType: 'industry-project',
    status: 'notification' as never,
    progress: null,
    message: 'Ishtar job delivered',
    data: { projectId },
    timestamp: '2026-11-06T10:05:00+00:00',
  }
}

async function mountPlanning() {
  vi.mocked(apiRequest).mockResolvedValue(LATE_PLANNING)
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  mount(ProjectPlanning, {
    props: { projectId: PLANNING_PROJECT_ID },
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

describe('ProjectPlanning realtime refresh', () => {
  it('reads the planning again on an event of its project, whatever the action, and not on an event of another project', async () => {
    await mountPlanning()
    const readsAtMount = planningReads()

    projectEvents.value = { [OTHER_PROJECT_ID]: contributionSynced() }
    await flushPromises()
    const readsAfterOtherProject = planningReads()
    projectEvents.value = { ...projectEvents.value, [PLANNING_PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({ readsAtMount, readsAfterOtherProject, readsAfterOwnEvent: planningReads() }).toEqual({
      readsAtMount: 1,
      readsAfterOtherProject: 1,
      readsAfterOwnEvent: 2,
    })
  })

  it('reads the planning again when a job of its project is delivered and not when a job of another project is', async () => {
    const syncStore = await mountPlanning()

    syncStore.syncStatus['industry-project'] = linkedJobProgress(OTHER_PROJECT_ID)
    await flushPromises()
    const readsAfterOtherProject = planningReads()
    syncStore.syncStatus['industry-project'] = linkedJobProgress(PLANNING_PROJECT_ID)
    await flushPromises()

    expect({ readsAfterOtherProject, readsAfterOwnProject: planningReads() }).toEqual({
      readsAfterOtherProject: 1,
      readsAfterOwnProject: 2,
    })
  })
})
