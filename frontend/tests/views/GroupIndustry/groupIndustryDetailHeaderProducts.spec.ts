import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { GroupProject } from '@/stores/group-industry/types'
import { mountCockpitDetail, nextAction, cockpitOf, nyxProject, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HEADER_PRODUCT = '[data-testid="project-header-product"]'
const HEADER_PRODUCTS_MORE = '[data-testid="project-header-products-more"]'
const HEADER_TITLE = '[data-testid="project-header-title"]'
const VISIBLE_HEADER_PRODUCTS = 8
const FIRST_PRODUCT_TYPE_ID = 40_000
const LONG_PROJECT_NAME = 'Capital hull batch for the long winter campaign with every component built in one chain'

function productsOf(count: number): GroupProject['items'] {
  return Array.from({ length: count }, (_, index) => ({
    typeId: FIRST_PRODUCT_TYPE_ID + index,
    typeName: `Product ${index + 1}`,
    meLevel: 8,
    teLevel: 16,
    runs: 2,
  }))
}

async function headerOf(items: GroupProject['items'], name: string | null = null) {
  const wrapper = await mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { items, name }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials' })),
  })
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project header products', () => {
  it('shows at most eight product icons and counts the rest when the project has 25 products', async () => {
    const wrapper = await headerOf(productsOf(25))

    expect({
      icons: wrapper.findAll(HEADER_PRODUCT).length,
      more: wrapper.find(HEADER_PRODUCTS_MORE).text(),
    }).toEqual({ icons: VISIBLE_HEADER_PRODUCTS, more: '+17' })
  })

  it('lists the hidden product names on the counter', async () => {
    const wrapper = await headerOf(productsOf(10))

    expect(wrapper.get(HEADER_PRODUCTS_MORE).attributes('title')).toBe('Product 9, Product 10')
  })

  it('shows every icon and no counter when the products fit', async () => {
    const wrapper = await headerOf(productsOf(VISIBLE_HEADER_PRODUCTS))

    expect({
      icons: wrapper.findAll(HEADER_PRODUCT).length,
      counter: wrapper.find(HEADER_PRODUCTS_MORE).exists(),
    }).toEqual({ icons: VISIBLE_HEADER_PRODUCTS, counter: false })
  })

  it('keeps a long project name in the title area with 25 products', async () => {
    const wrapper = await headerOf(productsOf(25), LONG_PROJECT_NAME)

    expect(wrapper.get(HEADER_TITLE).text()).toBe(LONG_PROJECT_NAME)
  })

  it('lets the header wrap its blocks instead of squeezing the title', async () => {
    const wrapper = await headerOf(productsOf(25), LONG_PROJECT_NAME)

    expect(wrapper.get('[data-testid="project-header"]').classes()).toContain('flex-wrap')
  })
})
