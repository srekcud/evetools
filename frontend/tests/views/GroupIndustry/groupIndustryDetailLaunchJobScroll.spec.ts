import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  nyxProject,
  route,
  tabSelector,
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

const JOBS_SECTION = '[data-testid="project-jobs-section"]'

const LAUNCH_NYX_COPY = nextAction({ code: 'launch_job', tab: 'materials', bomLineId: 'job-nyx-copy', typeId: 23_914, typeName: 'Nyx Blueprint Copy', quantity: 7 })
const RECORD_SALE = nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })

const scrollIntoView = vi.fn()

async function clickNextActionButton(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(NEXT_ACTION_BUTTON).trigger('click')
  await flushPromises()
}

function scrolledElements(): Element[] {
  return scrollIntoView.mock.contexts as Element[]
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  scrollIntoView.mockReset()
  Element.prototype.scrollIntoView = scrollIntoView
})

describe('project cockpit launch job action', () => {
  it('scrolls the jobs table into view when the materials tab is already open', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', LAUNCH_NYX_COPY),
    })

    await clickNextActionButton(wrapper)

    const section = wrapper.get(JOBS_SECTION).element
    expect({
      scrolled: scrolledElements(),
      containsJobsTable: section.contains(wrapper.getComponent(JobsTable).element),
    }).toEqual({ scrolled: [section], containsJobsTable: true })
  })

  it('goes back to the materials tab and scrolls to the jobs table when another tab is open', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', LAUNCH_NYX_COPY),
    })
    await wrapper.get(tabSelector('sales')).trigger('click')
    await flushPromises()
    const salesBefore = wrapper.findComponent(SalesTable).exists()

    await clickNextActionButton(wrapper)

    expect({
      salesBefore,
      salesAfter: wrapper.findComponent(SalesTable).exists(),
      scrolled: scrolledElements(),
    }).toEqual({ salesBefore: true, salesAfter: false, scrolled: [wrapper.get(JOBS_SECTION).element] })
  })

  it('does not scroll for an action that points to another tab', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', RECORD_SALE),
    })

    await clickNextActionButton(wrapper)

    expect({ scrolled: scrolledElements().length, sales: wrapper.findComponent(SalesTable).exists() }).toEqual({ scrolled: 0, sales: true })
  })
})
