import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPriceAlert } from '@/stores/group-industry/types'
import {
  cockpitOf,
  isSectionOpen,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LAUNCH_NYX_COPY = nextAction({ code: 'launch_job', tab: 'materials', bomLineId: 'job-nyx-copy', typeId: 23_914, typeName: 'Nyx Blueprint Copy', quantity: 7 })

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project price alert banner', () => {
  it('shows the price alert banner only when the cockpit carries one, and opens the sales section from its button', async () => {
    const priceAlert: ProjectPriceAlert = { typeId: 23_914, typeName: 'Nyx', quantity: 3, jitaUnitPrice: 690_000, breakEvenPerUnit: 760_000, gapPerUnit: 70_000 }
    const alerted = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', LAUNCH_NYX_COPY, undefined, priceAlert),
    })
    const calm = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', LAUNCH_NYX_COPY),
    })
    const banner = { alerted: alerted.find('[data-testid="price-alert-banner"]').exists(), calm: calm.find('[data-testid="price-alert-banner"]').exists() }
    const salesBefore = alerted.findComponent(SalesTable).exists()

    await alerted.get('[data-testid="price-alert-view-sales"]').trigger('click')
    await flushPromises()

    expect({ banner, salesBefore, salesAfter: alerted.findComponent(SalesTable).exists(), sectionOpen: isSectionOpen(alerted, 'sales') }).toEqual({
      banner: { alerted: true, calm: false },
      salesBefore: false,
      salesAfter: true,
      sectionOpen: true,
    })
  })
})
