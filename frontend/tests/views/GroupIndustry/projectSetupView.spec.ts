import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetup from '@/views/GroupIndustry/ProjectSetup.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole } from '@/stores/group-industry/types'
import {
  BASE_TYPE_ID,
  COMPONENTS_RECOMMENDED_ID,
  projectWorkStations,
  REACTION_RECOMMENDED_ID,
  REACTION_TYPE_ID,
  requestsOf,
  sourcingDecisions,
} from '../../projectSetupFixtures'
import { bomLine, nyxProject, PROJECT_ID, PROJECT_PATH } from './projectCockpitFixtures'

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

const BOM_PATH = `${PROJECT_PATH}/bom`
const PROJECT_PAGE = { name: 'industry-project', params: { id: PROJECT_ID } }

function answerSetup(myRole: GroupMemberRole): void {
  vi.mocked(apiRequest).mockImplementation((async (path: string, options?: { method?: string }) => {
    if (options?.method !== undefined) return {}
    if (path === PROJECT_PATH) return nyxProject('draft', myRole, { blacklistTypeIds: [] })
    if (path === `${PROJECT_PATH}/work-stations`) return projectWorkStations()
    if (path.startsWith(`${PROJECT_PATH}/sourcing-decisions`)) return sourcingDecisions(1000)
    if (path === BOM_PATH) {
      return [
        bomLine({ id: 'bom-line-reaction-job', typeId: REACTION_TYPE_ID, typeName: 'Fernite Carbide', isJob: true }),
        bomLine({ id: 'bom-line-reaction-purchase', typeId: REACTION_TYPE_ID, typeName: 'Fernite Carbide' }),
        bomLine({ id: 'bom-line-base-purchase', typeId: BASE_TYPE_ID, typeName: 'Tritanium' }),
      ]
    }
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

async function click(wrapper: VueWrapper, testId: string): Promise<void> {
  const element = byTestId(wrapper, testId)
  if (element.exists()) await element.trigger('click')
}

function navigations(): unknown[] {
  return [...push.mock.calls, ...replace.mock.calls].map(([target]) => target)
}

function writesOf(): unknown[] {
  return requestsOf(vi.mocked(apiRequest)).filter(request => request.method !== 'GET' || request.path === BOM_PATH)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.params = { id: PROJECT_ID }
  push.mockReset()
  replace.mockReset()
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectSetup view', () => {
  it('sends a member who is neither owner nor admin back to the project page without any write', async () => {
    answerSetup('member')

    const wrapper = await mountSetup()

    expect({
      navigations: navigations(),
      writes: requestsOf(vi.mocked(apiRequest)).filter(request => request.method !== 'GET'),
      assistantShown: byTestId(wrapper, 'project-setup').exists(),
    }).toEqual({ navigations: [PROJECT_PAGE], writes: [], assistantShown: false })
  })

  it('writes the stations, the blacklist and the capital market of the changed line on validate, then opens the project page', async () => {
    answerSetup('owner')
    const wrapper = await mountSetup()

    await click(wrapper, 'setup-next')
    await flushPromises()
    await click(wrapper, `sourcing-cell-${REACTION_TYPE_ID}-structure`)
    await flushPromises()
    await click(wrapper, 'setup-validate')
    await flushPromises()

    expect(writesOf()).toEqual([
      {
        method: 'PATCH',
        path: PROJECT_PATH,
        body: { workStations: { reaction: REACTION_RECOMMENDED_ID, components: COMPONENTS_RECOMMENDED_ID } },
      },
      { method: 'PATCH', path: PROJECT_PATH, body: { blacklistTypeIds: [REACTION_TYPE_ID] } },
      { method: 'GET', path: BOM_PATH, body: undefined },
      { method: 'POST', path: `${BOM_PATH}/bom-line-reaction-purchase/sourcing-market`, body: { market: 'structure' } },
    ])
    expect(navigations()).toEqual([PROJECT_PAGE])
  })
})
