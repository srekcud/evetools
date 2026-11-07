import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectStructureHealth from '@/components/group-industry/ProjectStructureHealth.vue'
import { apiRequest } from '@/services/api'
import type { ProjectStructureHealth as ProjectStructureHealthResource, StructureHealthRow } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const OTHER_PROJECT_ID = 'project-hecate-batch'
const HEALTH_PATH = `/industry/projects/${PROJECT_ID}/structure-health`
const LOW_FUEL_THRESHOLD_DAYS = 3
const BLANK = /[\u00a0\u202f]/g

const RAITARU: StructureHealthRow = {
  structureId: 'structure-raitaru',
  name: 'Jita Raitaru',
  kind: 'raitaru',
  state: 'low-fuel',
  fuelDays: 2.5,
  offlineServices: [],
}
const ATHANOR: StructureHealthRow = {
  structureId: 'structure-athanor',
  name: 'Reaction Athanor',
  kind: 'athanor',
  state: 'ok',
  fuelDays: 41,
  offlineServices: [],
}
const TATARA: StructureHealthRow = {
  structureId: 'structure-tatara',
  name: 'Invention Tatara',
  kind: 'tatara',
  state: 'offline-service',
  fuelDays: 41,
  offlineServices: ['Invention'],
}
const FOREIGN_STRUCTURE: StructureHealthRow = {
  structureId: 'structure-foreign',
  name: 'Foreign Fortizar',
  kind: 'fortizar',
  state: 'unknown',
  fuelDays: null,
  offlineServices: [],
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string, params: Record<string, unknown> = {}): string {
  return i18n.global.t(key, params).replace(BLANK, ' ')
}

function resource(rows: StructureHealthRow[], projectId = PROJECT_ID): ProjectStructureHealthResource {
  return { id: projectId, lowFuelThresholdDays: LOW_FUEL_THRESHOLD_DAYS, rows }
}

async function mountHealth(rows: StructureHealthRow[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(resource(rows))
  setActivePinia(createPinia())
  const wrapper = mount(ProjectStructureHealth, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function rowText(wrapper: VueWrapper, row: StructureHealthRow): string {
  const found = wrapper.find(`[data-testid="structure-health-row-${row.structureId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('project structure health card', () => {
  it('reads the health of the planned structures when it opens', async () => {
    await mountHealth([RAITARU])

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(HEALTH_PATH)
  })

  it('reads again when the project changes', async () => {
    const wrapper = await mountHealth([RAITARU])
    vi.mocked(apiRequest).mockResolvedValue(resource([ATHANOR], OTHER_PROJECT_ID))

    await wrapper.setProps({ projectId: OTHER_PROJECT_ID })
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([path]) => path)).toEqual([HEALTH_PATH, `/industry/projects/${OTHER_PROJECT_ID}/structure-health`])
    expect(rowText(wrapper, ATHANOR)).toContain('Reaction Athanor')
  })

  it('shows the fuel days of a structure with low fuel under its name and state', async () => {
    const wrapper = await mountHealth([RAITARU])

    const shown = rowText(wrapper, RAITARU)
    expect(shown).toContain('Jita Raitaru')
    expect(shown).toContain(text('groupIndustry.structureHealth.state.lowFuel'))
    expect(shown).toContain(text('groupIndustry.structureHealth.fuel', { days: 2.5 }))
  })

  it('shows a structure with plenty of fuel as ok', async () => {
    const wrapper = await mountHealth([ATHANOR])

    const shown = rowText(wrapper, ATHANOR)
    expect(shown).toContain(text('groupIndustry.structureHealth.state.ok'))
    expect(shown).toContain(text('groupIndustry.structureHealth.fuel', { days: 41 }))
  })

  it('names the services that are offline', async () => {
    const wrapper = await mountHealth([TATARA])

    const shown = rowText(wrapper, TATARA)
    expect(shown).toContain(text('groupIndustry.structureHealth.state.offlineService'))
    expect(shown).toContain(text('groupIndustry.structureHealth.offlineServices', { services: 'Invention' }))
  })

  it('shows an unknown structure as unknown with its reason, never as ok and without fuel', async () => {
    const wrapper = await mountHealth([FOREIGN_STRUCTURE])

    const shown = rowText(wrapper, FOREIGN_STRUCTURE)
    expect(shown).toContain(text('groupIndustry.structureHealth.state.unknown'))
    expect(shown).toContain(text('groupIndustry.structureHealth.unknownReason'))
    expect(shown).not.toContain(text('groupIndustry.structureHealth.state.ok'))
    expect(shown).not.toContain(text('groupIndustry.structureHealth.fuel', { days: 0 }))
  })

  it('lists the structures in the order of the response under a titled card', async () => {
    const wrapper = await mountHealth([RAITARU, ATHANOR, FOREIGN_STRUCTURE])

    expect(wrapper.find('[data-testid="structure-health"] h2').text()).toBe(text('groupIndustry.structureHealth.title'))
    expect(wrapper.findAll('[data-testid^="structure-health-row-"]').map(row => row.attributes('data-testid'))).toEqual([
      'structure-health-row-structure-raitaru',
      'structure-health-row-structure-athanor',
      'structure-health-row-structure-foreign',
    ])
  })

  it('says there is nothing to check when the plan has no planned structure', async () => {
    const wrapper = await mountHealth([])

    expect(wrapper.find('[data-testid="structure-health-empty"]').text()).toBe(text('groupIndustry.structureHealth.empty'))
  })

  it('shows the error when the health cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Server unreachable'))
    setActivePinia(createPinia())
    const wrapper = mount(ProjectStructureHealth, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    const alert = wrapper.find('[data-testid="structure-health-error"]')
    expect(alert.text()).toContain(text('groupIndustry.structureHealth.loadError'))
    expect(alert.text()).toContain('Server unreachable')
    expect(wrapper.find('[data-testid="structure-health-empty"]').exists()).toBe(false)
  })

  it('has the labels in English and French', () => {
    const keys = ['title', 'loading', 'loadError', 'empty', 'fuel', 'offlineServices', 'unknownReason', 'state.ok', 'state.lowFuel', 'state.offlineService', 'state.unknown']
    const missing = (['en', 'fr'] as const).flatMap(locale => keys.filter(key => i18n.global.te(`groupIndustry.structureHealth.${key}`, locale) === false))

    expect(missing).toEqual([])
  })
})
