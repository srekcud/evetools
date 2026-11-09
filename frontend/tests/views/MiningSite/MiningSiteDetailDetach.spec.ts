import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import { useMiningCompanionStore } from '@/stores/miningCompanion'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import {
  ORE_VALUES_PATH,
  SCANS_PATH,
  SITE_ID,
  SITE_PATH,
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

function answer(site: MiningSite, scans: MiningSiteScan[]): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITE_PATH) return site as never
    if (endpoint === SCANS_PATH) return scans as never
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountDetail() {
  const wrapper = mount(MiningSiteDetail, {
    global: {
      plugins: [i18nFor('en')],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSiteDetail detach button', () => {
  it('offers the detach button on an active site only and detaches the site without operation on click', async () => {
    const companion = useMiningCompanionStore()
    const detach = vi.spyOn(companion, 'detach').mockResolvedValue(undefined)
    answer(siteAfterThreeScans(), veldsparAndScorditeScans())
    const activeSite = await mountDetail()
    const onActiveSite = activeSite.find('[data-testid="site-detach"]').exists()

    await activeSite.find('[data-testid="site-detach"]').trigger('click')
    activeSite.unmount()
    answer(siteAfterThreeScans({ status: 'closed' }), veldsparAndScorditeScans())
    const closedSite = await mountDetail()

    expect({
      onActiveSite,
      onClosedSite: closedSite.find('[data-testid="site-detach"]').exists(),
      detachCalls: detach.mock.calls,
    }).toEqual({
      onActiveSite: true,
      onClosedSite: false,
      detachCalls: [[{ siteId: SITE_ID, operationId: null }]],
    })
  })
})
