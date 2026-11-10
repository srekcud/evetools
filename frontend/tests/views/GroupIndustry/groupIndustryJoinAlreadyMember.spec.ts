import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'

const SHORT_LINK_CODE = 'K7Q2MX'
const JOIN_ENDPOINT = `/industry/projects/join/${SHORT_LINK_CODE}`
const MY_PROJECTS_ENDPOINT = '/industry/projects'

const route = reactive<{ params: Record<string, string> }>({ params: { code: SHORT_LINK_CODE } })
const replace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OTHER_PROJECT = { id: 'project-104', shortLinkCode: 'P4R9ZD', myStatus: 'accepted' } as GroupProject
const MEMBER_PROJECT = { id: 'project-219', shortLinkCode: SHORT_LINK_CODE, myStatus: 'accepted' } as GroupProject

function answerJoin(joined: GroupProject | Error, myProjects: GroupProject[]): void {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === JOIN_ENDPOINT) {
      if (joined instanceof Error) throw joined
      return joined
    }
    if (endpoint === MY_PROJECTS_ENDPOINT) return myProjects
    throw new Error(`Unexpected request ${endpoint}`)
  }) as never)
}

async function mountJoin(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = mount(GroupIndustryJoin, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        LoadingSpinner: true,
        'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  replace.mockReset()
  route.params = { code: SHORT_LINK_CODE }
})

describe('GroupIndustryJoin for a pilot who already belongs to the project', () => {
  it('opens the project found by its short link code among the projects of the pilot', async () => {
    answerJoin(new Error('You are already a member of this project'), [OTHER_PROJECT, MEMBER_PROJECT])

    await mountJoin()

    expect(replace.mock.calls).toEqual([[{ name: 'industry-project', params: { id: 'project-219' } }]])
  })

  it('goes to the projects page when no project of the pilot has that short link code', async () => {
    answerJoin(new Error('You are already a member of this project'), [OTHER_PROJECT])

    await mountJoin()

    expect(replace.mock.calls).toEqual([[{ name: 'industry-projects' }]])
  })

  it('shows the failure and goes nowhere for a join error that is not an existing membership', async () => {
    answerJoin(new Error('Project not found'), [MEMBER_PROJECT])

    const wrapper = await mountJoin()

    expect(replace).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Project not found')
  })

  it.each([
    ['the waiting page', { ...MEMBER_PROJECT, myStatus: 'pending' } as GroupProject],
    ['the error page', new Error('Project not found')],
  ])('links the back button of %s to the projects page', async (_page, joined) => {
    answerJoin(joined, [])

    const wrapper = await mountJoin()

    expect(wrapper.get('a').attributes('data-to')).toBe(JSON.stringify({ name: 'industry-projects' }))
  })
})
