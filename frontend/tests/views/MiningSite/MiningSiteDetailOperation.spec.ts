import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import type { MiningSite } from '@/stores/miningSite'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import {
  OPERATION_ID,
  ORE_VALUES_PATH,
  SCANS_PATH,
  SITE_ID,
  SITE_PATH,
  at,
  i18nFor,
  oreValues,
  siteAfterThreeScans,
  veldsparAndScorditeScans,
} from './miningSiteFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: SITE_ID }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function answer(site: MiningSite): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITE_PATH) return site as never
    if (endpoint === SCANS_PATH) return veldsparAndScorditeScans() as never
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function linkTargets(site: MiningSite): Promise<Array<{ name: string; params?: Record<string, string> }>> {
  answer(site)
  const wrapper = mount(MiningSiteDetail, {
    global: {
      plugins: [i18nFor('en')],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  const targets = wrapper.findAllComponents(RouterLinkStub)
    .filter(link => link.element.closest('nav') === null)
    .map(link => link.props('to') as { name: string; params?: Record<string, string> })
  wrapper.unmount()
  return targets
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(at('20:40:00')))
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningSiteDetail, operation of the site', () => {
  it('links the operation of a site and goes back to the sites page, and offers no operation link for a site without operation', async () => {
    const withOperation = await linkTargets(siteAfterThreeScans({ operationId: OPERATION_ID }))
    const withoutOperation = await linkTargets(siteAfterThreeScans({ operationId: null }))

    expect({
      withOperation: withOperation.map(target => target.name).sort(),
      operationId: withOperation.find(target => target.name === 'mining-operation-detail')?.params,
      withoutOperation: withoutOperation.map(target => target.name),
    }).toEqual({
      withOperation: ['mining-operation-detail', 'mining-sites'],
      operationId: { id: OPERATION_ID },
      withoutOperation: ['mining-sites'],
    })
  })
})
