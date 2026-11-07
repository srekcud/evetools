import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectStructures from '@/components/group-industry/ProjectStructures.vue'
import { apiRequest } from '@/services/api'
import type { StructureComparisonGroup } from '@/stores/group-industry/types'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  EIGHT_JUMPS_STRUCTURE_ID,
  FERNITE_COMPOSITE_TYPE_ID,
  FERNITE_COMPOSITE_UNKNOWN_PRICE,
  HOBGOBLIN_II_TYPE_ID,
  HOBGOBLIN_II_WITHOUT_STRUCTURE,
  ISHTAR_CHEAPEST_RETAINED,
  ISHTAR_FAVORITE_SYSTEM_RETAINED,
  ISHTAR_TYPE_ID,
  projectStructures,
  REFINERY_STRUCTURE_ID,
  THREE_JUMPS_NAME,
  THREE_JUMPS_STRUCTURE_ID,
  WORKSHOP_STRUCTURE_ID,
} from '../../structureRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const OTHER_PROJECT_ID = 'project-hecate-batch'
const STRUCTURES_PATH = `/industry/projects/${PROJECT_ID}/structures`
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params).replace(BLANK, ' ')
}

async function mountStructures(
  groups: StructureComparisonGroup[],
  project: { acceptsOutsideFavoriteSystem?: boolean, minimumOutsideSaving?: number } = {},
): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(projectStructures(PROJECT_ID, groups, project))
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const wrapper = mount(ProjectStructures, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function part(wrapper: VueWrapper, typeId: number, structureId: string, name: string) {
  return wrapper.find(`[data-testid="structures-row-${typeId}-${structureId}-${name}"]`)
}

function partText(wrapper: VueWrapper, typeId: number, structureId: string, name: string): string {
  const found = part(wrapper, typeId, structureId, name)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function showsUnknownMarker(wrapper: VueWrapper, typeId: number, structureId: string, name: string): boolean {
  const found = part(wrapper, typeId, structureId, name)
  return found.exists() && found.find(`[title="${en.common.unknownValue}"]`).exists()
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function bannerText(wrapper: VueWrapper, typeId: number): string {
  return textOf(wrapper, `structures-banner-${typeId}`)
}

function structureReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === STRUCTURES_PATH).length
}

function contributionSynced(): GroupProjectEvent {
  return { action: 'contribution_synced', data: null, timestamp: '2026-11-07T10:00:00+00:00' }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

describe('ProjectStructures', () => {
  it('shows the group with its title, each structure with its system, jumps and amounts, the gap to the retained one and the transport rate', async () => {
    const wrapper = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED])

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(STRUCTURES_PATH)
    expect(textOf(wrapper, `structures-group-${ISHTAR_TYPE_ID}`))
      .toContain(text('groupIndustry.structures.groupTitle', { product: 'Ishtar', activity: text('groupIndustry.structures.activity.manufacturing'), jobs: 2, runs: 10 }))
    expect(textOf(wrapper, 'structures-rate')).toContain(text('groupIndustry.structures.transportRate', { rate: '150' }))

    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'name')).toContain('Maurasi Azbel')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'location')).toMatch(/azbel/i)
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'location')).toContain('Maurasi')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'location')).toMatch(/3\s+jumps/)
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'bonus')).toContain('10')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'materials')).toContain('9.00 M')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'installation')).toContain('850.00 K')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'installation')).toContain('0.5')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'transport')).toContain('900.00 K')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'transport')).toMatch(/2.?000\s*m³\s*×\s*3/)
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'total')).toContain('10.75 M')
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'delta')).toMatch(/[-−]\s?150\.00 K/)

    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'location')).toContain('Perimeter')
    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'location')).toContain(text('groupIndustry.structures.preferred'))
    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'location')).not.toMatch(/jump/)
    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'total')).toContain('10.90 M')

    expect(partText(wrapper, ISHTAR_TYPE_ID, EIGHT_JUMPS_STRUCTURE_ID, 'transport')).toContain('2.40 M')
    expect(partText(wrapper, ISHTAR_TYPE_ID, EIGHT_JUMPS_STRUCTURE_ID, 'total')).toContain('12.25 M')
    expect(partText(wrapper, ISHTAR_TYPE_ID, EIGHT_JUMPS_STRUCTURE_ID, 'delta')).toMatch(/\+\s?1\.35 M/)
  })

  it('shows an unknown amount as the unknown marker, never 0, while the known columns of the same row stay displayed', async () => {
    const wrapper = await mountStructures([FERNITE_COMPOSITE_UNKNOWN_PRICE])
    const unknownRow = (name: string) => showsUnknownMarker(wrapper, FERNITE_COMPOSITE_TYPE_ID, REFINERY_STRUCTURE_ID, name)

    expect(unknownRow('materials')).toBe(true)
    expect(unknownRow('total')).toBe(true)
    expect(unknownRow('delta')).toBe(true)
    expect(unknownRow('installation')).toBe(false)
    expect(unknownRow('transport')).toBe(false)
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, REFINERY_STRUCTURE_ID, 'installation')).toContain('425.00 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, REFINERY_STRUCTURE_ID, 'transport')).toContain('450.00 K')
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, REFINERY_STRUCTURE_ID, 'materials')).not.toMatch(/\b0\b/)
    expect(partText(wrapper, FERNITE_COMPOSITE_TYPE_ID, REFINERY_STRUCTURE_ID, 'total')).not.toMatch(/\b0\b/)
  })

  it('stars the structure retained by the plan and no other one', async () => {
    const wrapper = await mountStructures([ISHTAR_CHEAPEST_RETAINED])

    expect(part(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'retained').exists()).toBe(true)
    expect(partText(wrapper, ISHTAR_TYPE_ID, THREE_JUMPS_STRUCTURE_ID, 'retained')).toContain('★')
    expect(part(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'retained').exists()).toBe(false)
    expect(part(wrapper, ISHTAR_TYPE_ID, EIGHT_JUMPS_STRUCTURE_ID, 'retained').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid$="-retained"]')).toHaveLength(1)
  })

  it('explains in the wording of the project policy why the cheaper structure is not retained, and offers no button to change it', async () => {
    const amount = '150.00 K'
    const onlyFavoriteSystem = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], { acceptsOutsideFavoriteSystem: false })
    const belowThreshold = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 200_000 })
    const aboveThreshold = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 100_000 })

    expect(bannerText(onlyFavoriteSystem, ISHTAR_TYPE_ID)).toContain(text('groupIndustry.structures.banner.onlyFavoriteSystem', { structure: THREE_JUMPS_NAME, amount }))
    expect(bannerText(belowThreshold, ISHTAR_TYPE_ID)).toContain(text('groupIndustry.structures.banner.belowThreshold', { structure: THREE_JUMPS_NAME, amount, threshold: '200.00 K' }))
    expect(bannerText(aboveThreshold, ISHTAR_TYPE_ID)).toContain(text('groupIndustry.structures.banner.notRetained', { structure: THREE_JUMPS_NAME, amount }))
    expect(onlyFavoriteSystem.findAll('button')).toHaveLength(0)
  })

  it('informs that a structure outside the favorite system is retained for its lowest total cost and shows no banner for a group with nothing to warn about', async () => {
    const wrapper = await mountStructures([ISHTAR_CHEAPEST_RETAINED, FERNITE_COMPOSITE_UNKNOWN_PRICE], { acceptsOutsideFavoriteSystem: true })

    expect(bannerText(wrapper, ISHTAR_TYPE_ID)).toContain(text('groupIndustry.structures.banner.byTotalCost', { structure: THREE_JUMPS_NAME }))
    expect(bannerText(wrapper, FERNITE_COMPOSITE_TYPE_ID)).toBe('absent')
  })

  it('says there is nothing to compare when the plan has no manufacturing or reaction job', async () => {
    const wrapper = await mountStructures([])

    expect(textOf(wrapper, 'structures-empty')).toContain(en.groupIndustry.structures.empty)
    expect(wrapper.find('[data-testid="structures-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="structures-loading"]').exists()).toBe(false)
  })

  it('says no structure of the owner can run the activity of a group without row', async () => {
    const wrapper = await mountStructures([HOBGOBLIN_II_WITHOUT_STRUCTURE])

    expect(textOf(wrapper, `structures-group-${HOBGOBLIN_II_TYPE_ID}`)).toContain(en.groupIndustry.structures.noStructure)
    expect(wrapper.findAll(`[data-testid^="structures-row-${HOBGOBLIN_II_TYPE_ID}-"]`)).toHaveLength(0)
    expect(wrapper.find('[data-testid="structures-empty"]').exists()).toBe(false)
  })

  it('reads the structures again on an event of its project, whatever the action, and not on an event of another project', async () => {
    await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED])
    const readsAtMount = structureReads()

    projectEvents.value = { [OTHER_PROJECT_ID]: contributionSynced() }
    await flushPromises()
    const readsAfterOtherProject = structureReads()
    projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({ readsAtMount, readsAfterOtherProject, readsAfterOwnEvent: structureReads() }).toEqual({
      readsAtMount: 1,
      readsAfterOtherProject: 1,
      readsAfterOwnEvent: 2,
    })
  })
})
