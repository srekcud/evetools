import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: { tab: 'available' } })
const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MY_PROJECTS_URL = '/industry/projects'
const AVAILABLE_PROJECTS_URL = '/industry/projects/available'
const SHORT_LINK_CODE = 'R4T8VN'
const JOIN_URL = `/industry/projects/join/${SHORT_LINK_CODE}`

const AVAILABLE_PROJECT = { id: 'project-512', shortLinkCode: SHORT_LINK_CODE, myStatus: null, status: 'published', items: [] } as unknown as GroupProject
const ACCEPTED_PROJECT = { ...AVAILABLE_PROJECT, myStatus: 'accepted' } as GroupProject
const PENDING_PROJECT = { ...AVAILABLE_PROJECT, myStatus: 'pending' } as GroupProject

function answerApi(joinAnswer: () => Promise<GroupProject>): void {
  vi.mocked(apiRequest).mockImplementation(((url: string) => {
    if (url === MY_PROJECTS_URL) return Promise.resolve([])
    if (url === AVAILABLE_PROJECTS_URL) return Promise.resolve([AVAILABLE_PROJECT])
    if (url === JOIN_URL) return joinAnswer()
    return Promise.reject(new Error(`unexpected ${url}`))
  }) as never)
}

function callsTo(url: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledUrl]) => calledUrl === url).length
}

function answerLists(answers: { myProjects?: () => Promise<GroupProject[]>; availableProjects?: () => Promise<GroupProject[]> }): void {
  vi.mocked(apiRequest).mockImplementation(((url: string) => {
    if (url === MY_PROJECTS_URL) return (answers.myProjects ?? (() => Promise.resolve([])))()
    if (url === AVAILABLE_PROJECTS_URL) return (answers.availableProjects ?? (() => Promise.resolve([AVAILABLE_PROJECT])))()
    return Promise.reject(new Error(`unexpected ${url}`))
  }) as never)
}

function retryButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').filter(button => button.text() === messageOf('en', 'common.actions.retry'))[0]
}

async function mountList(locale: TestLocale = 'en'): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(GroupIndustryList, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        LoadingSpinner: true,
        ProjectKpiCards: true,
        ProjectCard: true,
        NewProjectModal: true,
        LineRentalModal: true,
        AvailableProjectCard: {
          props: ['project'],
          emits: ['join'],
          template: '<button class="join-project" @click="$emit(\'join\', project.shortLinkCode)">join</button>',
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

async function mountListAndJoin(locale: TestLocale = 'en'): Promise<VueWrapper> {
  const wrapper = await mountList(locale)
  await wrapper.get('.join-project').trigger('click')
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  push.mockReset()
  route.query = { tab: 'available' }
})

enableAutoUnmount(afterEach)

describe('GroupIndustryList join from the available projects', () => {
  it('opens the project detail once the membership is accepted', async () => {
    answerApi(() => Promise.resolve(ACCEPTED_PROJECT))

    await mountListAndJoin()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'industry-project', params: { id: 'project-512' } })
  })

  it('stays on the list when the membership is pending', async () => {
    answerApi(() => Promise.resolve(PENDING_PROJECT))

    await mountListAndJoin()

    expect(push).not.toHaveBeenCalled()
  })

  it('reloads my projects and the available projects when the membership is pending', async () => {
    answerApi(() => Promise.resolve(PENDING_PROJECT))

    await mountListAndJoin()

    expect(push).not.toHaveBeenCalled()
    expect(callsTo(MY_PROJECTS_URL)).toBe(2)
    expect(callsTo(AVAILABLE_PROJECTS_URL)).toBe(2)
  })

  it.each(TEST_LOCALES)('shows the %s pending approval message when the membership is pending', async (locale) => {
    answerApi(() => Promise.resolve(PENDING_PROJECT))

    const wrapper = await mountListAndJoin(locale)

    expect(push).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(messageOf(locale, 'groupIndustry.join.pendingApproval'))
  })

  it('shows the join failure without navigating nor reloading when the join request fails', async () => {
    answerApi(() => Promise.reject(new Error('Project is full')))

    const wrapper = await mountListAndJoin()

    expect(push).not.toHaveBeenCalled()
    expect(callsTo(MY_PROJECTS_URL)).toBe(1)
    expect(callsTo(AVAILABLE_PROJECTS_URL)).toBe(1)
    expect(wrapper.text()).toContain('Project is full')
  })

  it('keeps the available projects visible above the join failure banner', async () => {
    answerApi(() => Promise.reject(new Error('Project is full')))

    const wrapper = await mountListAndJoin()

    expect(wrapper.find('.join-project').exists()).toBe(true)
    expect(wrapper.text()).toContain('Project is full')
    expect(retryButton(wrapper)).toBeDefined()
  })

  it('retries the join and not the list reload when Retry is clicked after a join failure', async () => {
    let joinAttempts = 0
    answerApi(() => (++joinAttempts === 1 ? Promise.reject(new Error('Project is full')) : Promise.resolve(ACCEPTED_PROJECT)))
    const wrapper = await mountListAndJoin()

    await retryButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(callsTo(JOIN_URL)).toBe(2)
    expect(callsTo(MY_PROJECTS_URL)).toBe(1)
    expect(push).toHaveBeenCalledWith({ name: 'industry-project', params: { id: 'project-512' } })
  })

  it('clears the join failure banner once the retried join succeeds', async () => {
    let joinAttempts = 0
    answerApi(() => (++joinAttempts === 1 ? Promise.reject(new Error('Project is full')) : Promise.resolve(PENDING_PROJECT)))
    const wrapper = await mountListAndJoin()

    await retryButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Project is full')
    expect(wrapper.text()).toContain(messageOf('en', 'groupIndustry.join.pendingApproval'))
  })
})

describe('GroupIndustryList loading failure', () => {
  const EMPTY_STATE_TITLE = messageOf('en', 'groupIndustry.empty.title')

  it('shows the server message and not the empty state when my projects fail to load', async () => {
    route.query = { tab: 'my-projects' }
    answerLists({ myProjects: () => Promise.reject(new Error('Service unavailable')) })

    const wrapper = await mountList()

    expect(wrapper.text()).toContain('Service unavailable')
    expect(wrapper.text()).not.toContain(EMPTY_STATE_TITLE)
  })

  it('shows the server message when the available projects fail to load', async () => {
    answerLists({ availableProjects: () => Promise.reject(new Error('Gateway timeout')) })

    const wrapper = await mountList()

    expect(wrapper.text()).toContain('Gateway timeout')
    expect(wrapper.find('.join-project').exists()).toBe(false)
  })

  it('shows the server message of the first failure when both lists fail to load', async () => {
    answerLists({
      myProjects: () => Promise.reject(new Error('Service unavailable')),
      availableProjects: () => Promise.reject(new Error('Gateway timeout')),
    })

    const wrapper = await mountList()

    expect(wrapper.text()).toContain('Service unavailable')
  })

  it('reloads both lists and shows them when Retry is clicked after a loading failure', async () => {
    let myProjectsAttempts = 0
    answerLists({ myProjects: () => (++myProjectsAttempts === 1 ? Promise.reject(new Error('Service unavailable')) : Promise.resolve([])) })
    const wrapper = await mountList()

    await retryButton(wrapper)?.trigger('click')
    await flushPromises()

    expect(callsTo(MY_PROJECTS_URL)).toBe(2)
    expect(callsTo(AVAILABLE_PROJECTS_URL)).toBe(2)
    expect(wrapper.text()).not.toContain('Service unavailable')
    expect(wrapper.find('.join-project').exists()).toBe(true)
  })

  it('shows the empty state when my projects load as an empty list', async () => {
    route.query = { tab: 'my-projects' }
    answerLists({})

    const wrapper = await mountList()

    expect(wrapper.text()).toContain(EMPTY_STATE_TITLE)
  })
})
