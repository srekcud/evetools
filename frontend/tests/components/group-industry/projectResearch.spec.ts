import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import { apiRequest } from '@/services/api'
import type { ResearchRow } from '@/stores/group-industry/types'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  FERNITE_COMPOSITE_NOT_WORTH,
  FERNITE_COMPOSITE_TYPE_ID,
  HECATE_NOT_WORTH,
  HECATE_TYPE_ID,
  HOBGOBLIN_II_TYPE_ID,
  HOBGOBLIN_II_WORTH,
  ISHTAR_TYPE_ID,
  ISHTAR_WORTH,
  OGRE_II_TYPE_ID,
  OGRE_II_UNKNOWN_COST,
  projectResearch,
  researchRow,
  WARRIOR_II_NOT_WORTH,
  WARRIOR_II_TYPE_ID,
} from '../../researchRows'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const OTHER_PROJECT_ID = 'project-hecate-batch'
const RESEARCH_PATH = `/industry/projects/${PROJECT_ID}/research`
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

async function mountResearch(rows: ResearchRow[]) {
  vi.mocked(apiRequest).mockResolvedValue(projectResearch(PROJECT_ID, rows))
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const wrapper = mount(ProjectResearch, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, syncStore }
}

function part(wrapper: VueWrapper, typeId: number, name: string) {
  return wrapper.find(`[data-testid="research-row-${typeId}-${name}"]`)
}

function partText(wrapper: VueWrapper, typeId: number, name: string): string {
  const found = part(wrapper, typeId, name)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function showsUnknownMarker(wrapper: VueWrapper, typeId: number, name: string): boolean {
  const found = part(wrapper, typeId, name)
  return found.exists() && found.find(`[title="${en.common.unknownValue}"]`).exists()
}

function rowExists(wrapper: VueWrapper, typeId: number): boolean {
  return wrapper.find(`[data-testid="research-row-${typeId}"]`).exists()
}

function researchReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === RESEARCH_PATH).length
}

function contributionSynced(): GroupProjectEvent {
  return { action: 'contribution_synced', data: null, timestamp: '2026-11-09T10:00:00+00:00' }
}

function linkedJobProgress(projectId: string) {
  return {
    syncType: 'industry-project',
    status: 'notification' as never,
    progress: null,
    message: 'Ishtar job delivered',
    data: { projectId },
    timestamp: '2026-11-09T10:05:00+00:00',
  }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

describe('ProjectResearch', () => {
  it('proposes a worth research with its levels, the saving, the cost and the net, and the in-game confirmation notice', async () => {
    const { wrapper } = await mountResearch([HOBGOBLIN_II_WORTH])

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(RESEARCH_PATH)
    expect(wrapper.find('[data-testid="research"]').exists()).toBe(true)
    expect(wrapper.get(`[data-testid="research-row-${HOBGOBLIN_II_TYPE_ID}"]`).text()).toContain('Hobgoblin II')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'levels')).toContain('0')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'levels')).toContain('1')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'saving')).toContain('20.00 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'cost')).toContain('14.50 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'net')).toContain('5.50 K')
    expect(part(wrapper, HOBGOBLIN_II_TYPE_ID, 'unknown').exists()).toBe(false)
    expect(wrapper.find('[data-testid="research-in-game-notice"]').exists()).toBe(true)
  })

  it('shows each worth research with its own amounts, in millions when the saving is that large', async () => {
    const { wrapper } = await mountResearch([HOBGOBLIN_II_WORTH, ISHTAR_WORTH])

    expect(partText(wrapper, ISHTAR_TYPE_ID, 'levels')).toContain('3')
    expect(partText(wrapper, ISHTAR_TYPE_ID, 'levels')).toContain('4')
    expect(partText(wrapper, ISHTAR_TYPE_ID, 'saving')).toContain('1.25 M')
    expect(partText(wrapper, ISHTAR_TYPE_ID, 'cost')).toContain('310.40 K')
    expect(partText(wrapper, ISHTAR_TYPE_ID, 'net')).toContain('939.60 K')
    expect(partText(wrapper, HOBGOBLIN_II_TYPE_ID, 'net')).toContain('5.50 K')
  })

  it('shows an unknown research with a dash and the unknown marker on the cost and the net, never 0, and the saving that is known', async () => {
    const { wrapper } = await mountResearch([OGRE_II_UNKNOWN_COST])

    expect(rowExists(wrapper, OGRE_II_TYPE_ID)).toBe(true)
    expect(part(wrapper, OGRE_II_TYPE_ID, 'unknown').exists()).toBe(true)
    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'cost')).toBe(true)
    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'net')).toBe(true)
    expect(showsUnknownMarker(wrapper, OGRE_II_TYPE_ID, 'saving')).toBe(false)
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'saving')).toContain('18.40 K')
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'cost')).not.toMatch(/\b0\b/)
    expect(partText(wrapper, OGRE_II_TYPE_ID, 'net')).not.toMatch(/\b0\b/)
  })

  it('shows an unknown saving as the unknown marker while keeping the cost that is known', async () => {
    const unknownSaving = researchRow({
      typeId: HECATE_TYPE_ID,
      typeName: 'Hecate',
      currentLevel: 4,
      targetLevel: 5,
      runs: 6,
      materialSaving: null,
      researchCost: 21_000,
      net: null,
      verdict: 'unknown',
      missingData: [{ reason: 'material_price', typeId: 34 }],
    })
    const { wrapper } = await mountResearch([unknownSaving])

    expect(showsUnknownMarker(wrapper, HECATE_TYPE_ID, 'saving')).toBe(true)
    expect(showsUnknownMarker(wrapper, HECATE_TYPE_ID, 'net')).toBe(true)
    expect(showsUnknownMarker(wrapper, HECATE_TYPE_ID, 'cost')).toBe(false)
    expect(partText(wrapper, HECATE_TYPE_ID, 'cost')).toContain('21.00 K')
    expect(part(wrapper, HECATE_TYPE_ID, 'unknown').exists()).toBe(true)
  })

  it('counts the not worth researches without rendering them while the worth and unknown ones are rendered', async () => {
    const { wrapper } = await mountResearch([
      HOBGOBLIN_II_WORTH,
      WARRIOR_II_NOT_WORTH,
      OGRE_II_UNKNOWN_COST,
      FERNITE_COMPOSITE_NOT_WORTH,
      HECATE_NOT_WORTH,
    ])

    expect(rowExists(wrapper, HOBGOBLIN_II_TYPE_ID)).toBe(true)
    expect(rowExists(wrapper, OGRE_II_TYPE_ID)).toBe(true)
    expect(rowExists(wrapper, WARRIOR_II_TYPE_ID)).toBe(false)
    expect(rowExists(wrapper, FERNITE_COMPOSITE_TYPE_ID)).toBe(false)
    expect(rowExists(wrapper, HECATE_TYPE_ID)).toBe(false)
    expect(wrapper.get('[data-testid="research-not-worth"]').text()).toContain('3')
  })

  it('renders the card with the not worth counter and no row when every research is not worth', async () => {
    const { wrapper } = await mountResearch([WARRIOR_II_NOT_WORTH, FERNITE_COMPOSITE_NOT_WORTH])

    expect(wrapper.find('[data-testid="research"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="research-not-worth"]').text()).toContain('2')
    expect(wrapper.findAll('[data-testid^="research-row-"]')).toHaveLength(0)
  })

  it('does not render the card when the plan has no research suggestion', async () => {
    const { wrapper } = await mountResearch([])

    expect(wrapper.find('[data-testid="research"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="research-not-worth"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="research-error"]').exists()).toBe(false)
  })

  it('shows the loading state while the rows are being read', async () => {
    vi.mocked(apiRequest).mockReturnValue(deferred<unknown>().promise)
    setActivePinia(createPinia())

    const wrapper = mount(ProjectResearch, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('[data-testid="research-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="research-error"]').exists()).toBe(false)
  })

  it('shows the error of the request instead of an empty card', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Research unavailable: project not found'))
    setActivePinia(createPinia())

    const wrapper = mount(ProjectResearch, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.get('[data-testid="research-error"]').text()).toContain('Research unavailable: project not found')
    expect(wrapper.find('[data-testid="research-loading"]').exists()).toBe(false)
  })

  it('reads the research again on an event of its project and not on an event of another project', async () => {
    await mountResearch([HOBGOBLIN_II_WORTH])
    const readsAtMount = researchReads()

    projectEvents.value = { [OTHER_PROJECT_ID]: contributionSynced() }
    await flushPromises()
    const readsAfterOtherProject = researchReads()
    projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({ readsAtMount, readsAfterOtherProject, readsAfterOwnEvent: researchReads() }).toEqual({
      readsAtMount: 1,
      readsAfterOtherProject: 1,
      readsAfterOwnEvent: 2,
    })
  })

  it('reads the research again when a job of its project is delivered and not when a job of another project is', async () => {
    const { syncStore } = await mountResearch([HOBGOBLIN_II_WORTH])

    syncStore.syncStatus['industry-project'] = linkedJobProgress(OTHER_PROJECT_ID)
    await flushPromises()
    const readsAfterOtherProject = researchReads()
    syncStore.syncStatus['industry-project'] = linkedJobProgress(PROJECT_ID)
    await flushPromises()

    expect({ readsAfterOtherProject, readsAfterOwnProject: researchReads() }).toEqual({
      readsAfterOtherProject: 1,
      readsAfterOwnProject: 2,
    })
  })
})
