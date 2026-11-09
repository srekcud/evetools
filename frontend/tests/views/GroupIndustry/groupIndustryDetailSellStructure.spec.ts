import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { callsTo, COCKPIT_PATH, cockpitOf, nextAction, nyxProject, PROJECT_PATH, route, sentPatchBodies } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SETTINGS_ENDPOINT = '/me/settings'
const SELL_STRUCTURE_SELECT = '[data-testid="sell-structure-select"]'
const SELL_STRUCTURE_UNSET = '[data-testid="sell-structure-unset"]'
const JITA_4_4 = { id: 60_003_760, name: 'Jita 4-4' }
const KEEPSTAR_A = { id: 1_042_000_000_001, name: 'Keepstar A' }

type Settings = {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: { id: number; name: string }[]
}

const SETTINGS_WITH_KEEPSTAR: Settings = {
  preferredMarketStructureId: JITA_4_4.id,
  preferredMarketStructureName: JITA_4_4.name,
  marketStructures: [JITA_4_4, KEEPSTAR_A],
}
const SETTINGS_WITHOUT_STRUCTURE: Settings = { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] }

async function openEditModalOf(project: GroupProject, settings: Settings): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SETTINGS_ENDPOINT) return settings
    if (options?.method === 'PATCH') return { ...project, ...JSON.parse(String(options.body)) }
    if (endpoint === PROJECT_PATH) return project
    if (endpoint === COCKPIT_PATH) return cockpitOf(project.status, nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 }))
    return []
  })
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ContributeModal: true,
        ContributionTable: true,
        SalesTable: true,
        SaleSuggestionsPanel: true,
        FinancialSummary: true,
        DistributionTable: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
        LoadingSpinner: true,
        ConfirmModal: true,
      },
    },
  })
  await flushPromises()
  await wrapper.get(`button[aria-label="${en.groupIndustry.detail.edit}"]`).trigger('click')
  await flushPromises()
  return wrapper
}

async function save(wrapper: VueWrapper): Promise<void> {
  const saveButton = wrapper.findAll('button').find(button => button.text() === en.common.actions.save)
  if (saveButton === undefined) throw new Error('no save button')
  await saveButton.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('group project edit modal sell structure', () => {
  it('leaves sellStructureId out of the PATCH when the selection did not change', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { sellStructureId: JITA_4_4.id, sellStructureName: JITA_4_4.name }), SETTINGS_WITH_KEEPSTAR)

    expect((wrapper.get(SELL_STRUCTURE_SELECT).element as HTMLSelectElement).value).toBe('60003760')
    await save(wrapper)

    expect(sentPatchBodies()).toHaveLength(1)
    expect(sentPatchBodies()[0]).not.toHaveProperty('sellStructureId')
  })

  it('sends the new sellStructureId in the PATCH when the user picks another structure', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { sellStructureId: JITA_4_4.id, sellStructureName: JITA_4_4.name }), SETTINGS_WITH_KEEPSTAR)

    await wrapper.get(SELL_STRUCTURE_SELECT).setValue(String(KEEPSTAR_A.id))
    await save(wrapper)

    expect(sentPatchBodies()).toEqual([expect.objectContaining({ sellStructureId: 1_042_000_000_001 })])
  })

  it('shows a project without sell structure as not set, never as Jita, when no preferred structure can be preselected', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { sellStructureId: null, sellStructureName: null }), SETTINGS_WITHOUT_STRUCTURE)

    expect(callsTo(SETTINGS_ENDPOINT)).toBe(1)
    expect(wrapper.get(SELL_STRUCTURE_UNSET).text()).toBe('Not set')
    expect(wrapper.get(SELL_STRUCTURE_UNSET).element.parentElement?.textContent).not.toContain('Jita')
  })
})
