import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetup from '@/views/GroupIndustry/ProjectSetup.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupProject } from '@/stores/group-industry/types'
import {
  BASE_TYPE_ID,
  COMPONENTS_RECOMMENDED_ID,
  projectWorkStations,
  REACTION_ALTERNATIVE_ID,
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
const WORK_STATIONS_PATH = `${PROJECT_PATH}/work-stations`
const COMPLETED_PROJECT_REFUSAL = 'The structure policy of a completed project cannot be changed'
const PROJECT_PAGE = { name: 'industry-project', params: { id: PROJECT_ID } }

type SetupAnswers = { project?: Partial<GroupProject>; patch?: (body: Record<string, unknown>) => unknown }

function answerSetup(myRole: GroupMemberRole, answers: SetupAnswers = {}): void {
  vi.mocked(apiRequest).mockImplementation((async (path: string, options?: { method?: string; body?: string }) => {
    if (options?.method === 'PATCH' && answers.patch !== undefined) return answers.patch(JSON.parse(options.body ?? '{}'))
    if (options?.method !== undefined) return {}
    if (path === PROJECT_PATH) return nyxProject('draft', myRole, { blacklistTypeIds: [], ...answers.project })
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

async function setPolicyCheckbox(wrapper: VueWrapper, checked: boolean): Promise<void> {
  const checkbox = byTestId(wrapper, 'stations-policy-outside')
  if (checkbox.exists()) await checkbox.setValue(checked)
}

function isPolicyChecked(wrapper: VueWrapper): boolean {
  const checkbox = byTestId(wrapper, 'stations-policy-outside')
  return checkbox.exists() && (checkbox.element as HTMLInputElement).checked
}

function patchesOf(): unknown[] {
  return requestsOf(vi.mocked(apiRequest)).filter(request => request.method === 'PATCH')
}

function workStationsReads(): number {
  return requestsOf(vi.mocked(apiRequest)).filter(request => request.method === 'GET' && request.path === WORK_STATIONS_PATH).length
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

  it('sends only the toggled policy from the station step, reads the work stations again and keeps the station already picked', async () => {
    answerSetup('owner', { patch: body => ('acceptsOutsideFavoriteSystem' in body ? { acceptsOutsideFavoriteSystem: body.acceptsOutsideFavoriteSystem } : {}) })
    const wrapper = await mountSetup()
    await click(wrapper, `station-radio-reaction-${REACTION_ALTERNATIVE_ID}`)
    const readsBefore = workStationsReads()

    await setPolicyCheckbox(wrapper, true)
    await flushPromises()
    const afterToggle = {
      patches: patchesOf(),
      reads: workStationsReads(),
      checked: isPolicyChecked(wrapper),
      pickedRadio: [
        byTestId(wrapper, `station-radio-reaction-${REACTION_ALTERNATIVE_ID}`).exists() && (byTestId(wrapper, `station-radio-reaction-${REACTION_ALTERNATIVE_ID}`).element as HTMLInputElement).checked,
        byTestId(wrapper, `station-radio-reaction-${REACTION_RECOMMENDED_ID}`).exists() && (byTestId(wrapper, `station-radio-reaction-${REACTION_RECOMMENDED_ID}`).element as HTMLInputElement).checked,
      ],
    }

    expect({ readsBefore, afterToggle }).toEqual({
      readsBefore: 1,
      afterToggle: {
        patches: [{ method: 'PATCH', path: PROJECT_PATH, body: { acceptsOutsideFavoriteSystem: true } }],
        reads: 2,
        checked: true,
        pickedRadio: [true, false],
      },
    })
  })

  it('sends only the saved threshold from the station step and reads the work stations again', async () => {
    answerSetup('owner', {
      project: { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 5 },
      patch: body => ('minimumOutsideSaving' in body ? { minimumOutsideSaving: body.minimumOutsideSaving } : {}),
    })
    const wrapper = await mountSetup()
    const input = byTestId(wrapper, 'stations-policy-threshold-input')
    if (input.exists()) await input.setValue('20')

    await click(wrapper, 'stations-policy-threshold-save')
    await flushPromises()

    expect({
      patches: patchesOf(),
      reads: workStationsReads(),
      thresholdShown: byTestId(wrapper, 'stations-policy-threshold-input').exists() ? (byTestId(wrapper, 'stations-policy-threshold-input').element as HTMLInputElement).value : 'input-absent',
    }).toEqual({
      patches: [{ method: 'PATCH', path: PROJECT_PATH, body: { minimumOutsideSaving: 20 } }],
      reads: 2,
      thresholdShown: '20',
    })
  })

  it('shows the refusal of the server in the policy block, keeps the stored checkbox and does not read the work stations again', async () => {
    answerSetup('owner', {
      patch: () => {
        throw new Error(COMPLETED_PROJECT_REFUSAL)
      },
    })
    const wrapper = await mountSetup()

    await setPolicyCheckbox(wrapper, true)
    await flushPromises()

    expect({
      error: byTestId(wrapper, 'stations-policy-error').exists() ? byTestId(wrapper, 'stations-policy-error').text() : 'error-absent',
      checked: isPolicyChecked(wrapper),
      reads: workStationsReads(),
      patches: patchesOf(),
    }).toEqual({
      error: 'The project is completed: its material list can no longer change.',
      checked: false,
      reads: 1,
      patches: [{ method: 'PATCH', path: PROJECT_PATH, body: { acceptsOutsideFavoriteSystem: true } }],
    })
  })
})
