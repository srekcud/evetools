import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectStructures from '@/components/group-industry/ProjectStructures.vue'
import { apiRequest } from '@/services/api'
import { deferred } from '../../deferred'
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
  structureGroup,
  structureRow,
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

function ishtarWithWorkshopTaxOf(facilityTaxPercent: number | null): StructureComparisonGroup {
  return structureGroup({
    typeId: ISHTAR_TYPE_ID,
    typeName: 'Ishtar',
    jobs: 1,
    runs: 10,
    chosenStructureId: WORKSHOP_STRUCTURE_ID,
    choiceStatus: 'best_in_favorite_system',
    rows: [structureRow({
      structureId: WORKSHOP_STRUCTURE_ID,
      name: 'Perimeter Raitaru',
      kind: 'raitaru',
      solarSystemId: 30_000_144,
      solarSystemName: 'Perimeter',
      inFavoriteSystem: true,
      jumps: 0,
      facilityTaxPercent,
      materialsCost: 10_000_000,
      installationCost: facilityTaxPercent === null ? null : 900_000,
      transportCost: 0,
      transportedCubicMeters: 2_000,
      totalCost: facilityTaxPercent === null ? null : 10_900_000,
      delta: facilityTaxPercent === null ? null : 0,
      missingData: facilityTaxPercent === null ? [{ reason: 'facility_tax', typeId: null }] : [],
    })],
  })
}

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params).replace(BLANK, ' ')
}

async function mountStructures(
  groups: StructureComparisonGroup[],
  project: { acceptsOutsideFavoriteSystem?: boolean, minimumOutsideSaving?: number } = {},
  canSwitch = false,
  patchOutcome: () => Promise<unknown> = () => Promise.resolve({}),
): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((_endpoint: string, options?: RequestInit) =>
    Promise.resolve(options?.method === 'PATCH' ? patchOutcome() : projectStructures(PROJECT_ID, groups, project)),
  )
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const wrapper = mount(ProjectStructures, { props: { projectId: PROJECT_ID, canSwitch }, global: { plugins: [i18n] } })
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

  it('says the facility tax was not entered, in the wording of the locale, when the structure has none', async () => {
    i18n.global.locale.value = 'fr'
    try {
      const wrapper = await mountStructures([ishtarWithWorkshopTaxOf(null)])

      expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'installation')).toContain('non saisie')
      expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'installation')).not.toMatch(/\b0\s%/)
    } finally {
      i18n.global.locale.value = 'en'
    }
  })

  it('shows a facility tax entered as zero as 0 % and not as a tax left empty', async () => {
    const wrapper = await mountStructures([ishtarWithWorkshopTaxOf(0)])

    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'installation')).toContain(text('groupIndustry.structures.taxDetail', { percent: '0 %' }))
    expect(partText(wrapper, ISHTAR_TYPE_ID, WORKSHOP_STRUCTURE_ID, 'installation')).not.toContain('non saisie')
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

  describe('policy button', () => {
    const SWITCH_REFUSAL = 'The line "Fernite Composite" carries a linked job, a purchase or a contribution and cannot be removed from the plan.'

    function switchButton(wrapper: VueWrapper) {
      return wrapper.find(`[data-testid="structures-banner-${ISHTAR_TYPE_ID}-switch"]`)
    }

    function patchesSent(): string[] {
      return vi.mocked(apiRequest).mock.calls
        .filter(([endpoint, options]) => endpoint === `/industry/projects/${PROJECT_ID}` && options?.method === 'PATCH')
        .map(([, options]) => String(options?.body))
    }

    async function clickSwitch(wrapper: VueWrapper): Promise<void> {
      const button = switchButton(wrapper)
      expect(button.exists()).toBe(true)
      if (button.exists()) await button.trigger('click')
      await flushPromises()
    }

    it('offers to allow the project outside the favorite system when the cheaper structure is refused for that reason, and sends the option', async () => {
      const wrapper = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], { acceptsOutsideFavoriteSystem: false }, true)

      expect(switchButton(wrapper).text()).toBe(text('groupIndustry.structures.switch.allow'))

      await clickSwitch(wrapper)

      expect(patchesSent()).toEqual([JSON.stringify({ acceptsOutsideFavoriteSystem: true })])
    })

    it.each([
      ['below the threshold', ISHTAR_FAVORITE_SYSTEM_RETAINED, 200_000],
      ['above the threshold', ISHTAR_FAVORITE_SYSTEM_RETAINED, 100_000],
      ['retained for its total cost', ISHTAR_CHEAPEST_RETAINED, 100_000],
    ])('offers to go back to the favorite system when the project accepts the outside and the saving is %s, and sends the option', async (_case, group, minimumOutsideSaving) => {
      const wrapper = await mountStructures([group], { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving }, true)

      expect(switchButton(wrapper).text()).toBe(text('groupIndustry.structures.switch.revert'))

      await clickSwitch(wrapper)

      expect(patchesSent()).toEqual([JSON.stringify({ acceptsOutsideFavoriteSystem: false })])
    })

    it('shows no button without the right to switch and none in a group without banner', async () => {
      const withoutRight = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], { acceptsOutsideFavoriteSystem: false }, false)
      const withoutBanner = await mountStructures([FERNITE_COMPOSITE_UNKNOWN_PRICE], { acceptsOutsideFavoriteSystem: true }, true)

      expect(withoutRight.findAll('button')).toHaveLength(0)
      expect(withoutBanner.findAll('button')).toHaveLength(0)
    })

    it('disables the button while the project is being updated', async () => {
      const pending = deferred<unknown>()
      const wrapper = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], {}, true, () => pending.promise)

      await clickSwitch(wrapper)
      const whileUpdating = switchButton(wrapper).attributes('disabled')
      pending.resolve({})
      await flushPromises()

      expect(whileUpdating).toBeDefined()
      expect(switchButton(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('reads the structures again and emits switched once the option is saved', async () => {
      const wrapper = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], {}, true)
      const readsBeforeTheSwitch = structureReads()

      await clickSwitch(wrapper)

      expect([readsBeforeTheSwitch, structureReads()]).toEqual([1, 2])
      expect(wrapper.emitted('switched')).toHaveLength(1)
      expect(wrapper.find('[data-testid="structures-switch-error"]').exists()).toBe(false)
    })

    it('shows the message of the server in an alert and does not emit switched when the option is refused', async () => {
      const wrapper = await mountStructures([ISHTAR_FAVORITE_SYSTEM_RETAINED], {}, true, () => Promise.reject(new Error(SWITCH_REFUSAL)))

      await clickSwitch(wrapper)

      const alert = wrapper.get('[data-testid="structures-switch-error"]')
      expect(alert.attributes('role')).toBe('alert')
      expect(alert.text()).toContain(text('groupIndustry.structures.switch.error'))
      expect(alert.text()).toContain(SWITCH_REFUSAL)
      expect(wrapper.emitted('switched')).toBeUndefined()
      expect(structureReads()).toBe(1)
    })
  })
})
