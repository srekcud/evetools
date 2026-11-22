import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetup from '@/views/GroupIndustry/ProjectSetup.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import { COMPONENTS_RECOMMENDED_ID, projectWorkStations, REACTION_RECOMMENDED_ID, requestsOf, sourcingDecisions } from '../../projectSetupFixtures'
import { nyxProject, PROJECT_ID, PROJECT_PATH } from './projectCockpitFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: PROJECT_ID }, query: {} })
const push = vi.fn()
const replace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push, replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PAGE = { name: 'industry-project', params: { id: PROJECT_ID } }
const REFUSAL = 'The station of the reaction work cannot change once a job is launched or a purchase is made.'

function answerSetup(status: GroupProjectStatus, myRole: GroupMemberRole, refuseWrite = false): void {
  vi.mocked(apiRequest).mockImplementation((async (path: string, options?: { method?: string }) => {
    if (options?.method !== undefined) {
      if (refuseWrite) throw new Error(REFUSAL)
      return {}
    }
    if (path === PROJECT_PATH) return nyxProject(status, myRole, { blacklistTypeIds: [] })
    if (path === `${PROJECT_PATH}/work-stations`) return projectWorkStations()
    if (path.startsWith(`${PROJECT_PATH}/sourcing-decisions`)) return sourcingDecisions(1000)
    return []
  }) as never)
}

async function mountSetup(): Promise<VueWrapper> {
  const wrapper = mount(ProjectSetup, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, IndustryModuleTabs: true, teleport: true, LoadingSpinner: true },
    },
  })
  await flushPromises()
  return wrapper
}

function byTestId(wrapper: VueWrapper, testId: string) {
  return wrapper.find(`[data-testid="${testId}"]`)
}

function navigations(): unknown[] {
  return [...push.mock.calls, ...replace.mock.calls].map(([target]) => target)
}

function writesOf() {
  return requestsOf(vi.mocked(apiRequest)).filter(request => request.method !== 'GET')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.params = { id: PROJECT_ID }
  route.query = { edit: 'stations' }
  push.mockReset()
  replace.mockReset()
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectSetup view, edit stations', () => {
  it('opens on a project already in progress with the stations only, without reading the sourcing decisions', async () => {
    answerSetup('in_progress', 'owner')

    const wrapper = await mountSetup()

    expect({
      assistantShown: byTestId(wrapper, 'project-setup').exists(),
      validateShown: byTestId(wrapper, 'setup-validate').exists(),
      nextShown: byTestId(wrapper, 'setup-next').exists(),
      sourcingRequests: requestsOf(vi.mocked(apiRequest)).filter(request => request.path.includes('/sourcing-decisions')),
      navigations: navigations(),
    }).toEqual({ assistantShown: true, validateShown: true, nextShown: false, sourcingRequests: [], navigations: [] })
  })

  it('writes only the stations on save, then opens the project page', async () => {
    answerSetup('in_progress', 'owner')
    const wrapper = await mountSetup()

    await byTestId(wrapper, 'setup-validate').trigger('click')
    await flushPromises()

    expect({ writes: writesOf(), navigations: navigations() }).toEqual({
      writes: [{ method: 'PATCH', path: PROJECT_PATH, body: { workStations: { reaction: REACTION_RECOMMENDED_ID, components: COMPONENTS_RECOMMENDED_ID } } }],
      navigations: [PROJECT_PAGE],
    })
  })

  it('shows the refusal of the server and stays on the setup', async () => {
    answerSetup('in_progress', 'owner', true)
    const wrapper = await mountSetup()

    await byTestId(wrapper, 'setup-validate').trigger('click')
    await flushPromises()

    expect({ error: byTestId(wrapper, 'setup-save-error').text(), navigations: navigations() }).toEqual({
      error: expect.stringContaining(REFUSAL),
      navigations: [],
    })
  })

  it('sends a member and a completed project back to the project page', async () => {
    answerSetup('in_progress', 'member')
    await mountSetup()
    const memberNavigations = navigations()
    replace.mockReset()
    answerSetup('completed', 'owner')
    await mountSetup()

    expect([memberNavigations, navigations()]).toEqual([[PROJECT_PAGE], [PROJECT_PAGE]])
  })

  it('still sends an in progress project back to the project page without the edit stations query', async () => {
    route.query = {}
    answerSetup('in_progress', 'owner')

    const wrapper = await mountSetup()

    expect({ navigations: navigations(), assistantShown: byTestId(wrapper, 'project-setup').exists() }).toEqual({
      navigations: [PROJECT_PAGE],
      assistantShown: false,
    })
  })
})
