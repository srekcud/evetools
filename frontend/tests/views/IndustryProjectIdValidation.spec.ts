import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import { apiRequest } from '@/services/api'

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_DETAIL_ENDPOINT_PREFIX = '/industry/projects/'
const NIDHOGGUR_PROJECT_ID = '0192f4a8-7c3e-7d21-9b6a-4e5f8a1c2d30'
const MOROS_PROJECT_ID = '0192f4a9-1b2c-7e43-8a5d-6f7e9b0c1d42'

async function mountIndustryWithQuery(query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, {
    shallow: true,
    global: {
      plugins: [i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, ProjectDetail: false, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

async function changeQuery(query: LocationQuery): Promise<void> {
  route.query = query
  await flushPromises()
}

function projectDetailEndpointsCalled(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint.startsWith(PROJECT_DETAIL_ENDPOINT_PREFIX))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockReturnValue(new Promise(() => {}))
})

describe('Industry project identifier in the URL', () => {
  it('opens the detail of a project whose identifier is a UUID and loads only that project', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects', project: NIDHOGGUR_PROJECT_ID })

    expect(wrapper.findComponent(ProjectDetail).props('projectId')).toBe(NIDHOGGUR_PROJECT_ID)
    expect(projectDetailEndpointsCalled()).toEqual([`${PROJECT_DETAIL_ENDPOINT_PREFIX}${NIDHOGGUR_PROJECT_ID}`])
  })

  it.each([
    ['../me'],
    ['a/b'],
    ['%2e%2e%2fme'],
    ['p1?x=1'],
  ])('opens no detail and loads no project when mounted with the project identifier %s', async (projectId) => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects', project: projectId })

    expect(wrapper.findComponent(ProjectDetail).exists()).toBe(false)
    expect(projectDetailEndpointsCalled()).toEqual([])
  })

  it('opens no detail and loads no project when a malformed identifier appears in the query of a mounted screen', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects' })

    await changeQuery({ tab: 'projects', project: '../me' })

    expect(wrapper.findComponent(ProjectDetail).exists()).toBe(false)
    expect(projectDetailEndpointsCalled()).toEqual([])
  })

  it('still opens a valid project after a malformed identifier was ignored', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects', project: 'a/b' })

    await changeQuery({ tab: 'projects', project: MOROS_PROJECT_ID })

    expect(wrapper.findComponent(ProjectDetail).props('projectId')).toBe(MOROS_PROJECT_ID)
    expect(projectDetailEndpointsCalled()).toEqual([`${PROJECT_DETAIL_ENDPOINT_PREFIX}${MOROS_PROJECT_ID}`])
  })
})
