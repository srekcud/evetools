import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { COCKPIT_PATH, cockpitOf, nextAction, nyxProject, PROJECT_PATH, route, sentPatchBodies } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CHARACTERS_ENDPOINT = '/me/characters'
const ARIA = { id: 'uuid-aria', eveCharacterId: 2_112_000_001, name: 'Aria Solberg' }
const BRIN = { id: 'uuid-brin', eveCharacterId: 2_112_000_002, name: 'Brin Solberg' }

function box(wrapper: VueWrapper, eveCharacterId: number) {
  return wrapper.get(`[data-testid="producer-character-${eveCharacterId}"]`)
}

async function openEditModalOf(project: GroupProject, characters: unknown): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === CHARACTERS_ENDPOINT) return characters
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
        IndustryModuleTabs: true,
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

describe('group project edit modal producer characters', () => {
  it('ticks every character of the owner when the project has no stored selection', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { producerCharacterIds: null }), [ARIA, BRIN])

    expect((box(wrapper, ARIA.eveCharacterId).element as HTMLInputElement).checked).toBe(true)
    expect((box(wrapper, BRIN.eveCharacterId).element as HTMLInputElement).checked).toBe(true)
  })

  it('ticks only the stored producer characters and leaves producerCharacterIds out of the PATCH when nothing changed', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { producerCharacterIds: [ARIA.eveCharacterId] }), [ARIA, BRIN])

    expect((box(wrapper, ARIA.eveCharacterId).element as HTMLInputElement).checked).toBe(true)
    expect((box(wrapper, BRIN.eveCharacterId).element as HTMLInputElement).checked).toBe(false)
    await save(wrapper)

    expect(sentPatchBodies()).toHaveLength(1)
    expect(sentPatchBodies()[0]).not.toHaveProperty('producerCharacterIds')
  })

  it('sends the new producer characters in the PATCH when the user ticks another character', async () => {
    const wrapper = await openEditModalOf(nyxProject('selling', 'owner', { producerCharacterIds: [ARIA.eveCharacterId] }), [ARIA, BRIN])

    await box(wrapper, BRIN.eveCharacterId).setValue(true)
    await save(wrapper)

    expect(sentPatchBodies()).toEqual([expect.objectContaining({ producerCharacterIds: [2_112_000_001, 2_112_000_002] })])
  })

  it('does not let a completed project change its producer characters', async () => {
    const wrapper = await openEditModalOf(nyxProject('completed', 'owner', { producerCharacterIds: null }), [ARIA, BRIN])

    expect(box(wrapper, ARIA.eveCharacterId).attributes('disabled')).toBeDefined()
    expect(box(wrapper, BRIN.eveCharacterId).attributes('disabled')).toBeDefined()
  })
})
