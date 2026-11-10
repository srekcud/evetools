import { enableAutoUnmount } from '@vue/test-utils'
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { mountMainLayoutAt } from './mainLayoutHarness'

const DEFAULT_MINING_PAGE = '/mining/operations'
const LAST_VISITED_MOONS_PAGE = '/mining/moons'

const DEFAULT_INDUSTRY_PAGE = '/industry'
const LAST_VISITED_ANALYSIS_PAGE = '/industry/analysis'

function industryLink(wrapper: VueWrapper): DOMWrapper<Element> | undefined {
  return wrapper.get('aside nav').findAll('a[href]').find(link => link.text().trim() === 'Industry')
}

function miningLink(wrapper: VueWrapper): DOMWrapper<Element> | undefined {
  return wrapper.get('aside nav').findAll('a[href]').find(link => link.text().trim() === 'Mining')
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MainLayout mining entry', () => {
  it('E7 opens the last visited mining page from the sidebar', async () => {
    const { wrapper, router } = await mountMainLayoutAt('/dashboard', {
      storedModuleLastPage: JSON.stringify({ mining: LAST_VISITED_MOONS_PAGE }),
    })

    expect(miningLink(wrapper)?.attributes('href')).toBe(LAST_VISITED_MOONS_PAGE)
    await miningLink(wrapper)?.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe(LAST_VISITED_MOONS_PAGE))
    expect(miningLink(wrapper)?.attributes('aria-current')).toBe('page')
  })

  it('E8 opens the operations page when the stored last page is unreadable', async () => {
    const { wrapper, router } = await mountMainLayoutAt('/dashboard', { storedModuleLastPage: '{' })

    expect(miningLink(wrapper)?.attributes('href')).toBe(DEFAULT_MINING_PAGE)
    await miningLink(wrapper)?.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe(DEFAULT_MINING_PAGE))
  })
})

describe('MainLayout industry entry', () => {
  it('opens the last visited industry page from the sidebar', async () => {
    const { wrapper, router } = await mountMainLayoutAt('/dashboard', {
      storedModuleLastPage: JSON.stringify({ industry: LAST_VISITED_ANALYSIS_PAGE }),
    })

    expect(industryLink(wrapper)?.attributes('href')).toBe(LAST_VISITED_ANALYSIS_PAGE)
    await industryLink(wrapper)?.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe(LAST_VISITED_ANALYSIS_PAGE))
    expect(industryLink(wrapper)?.attributes('aria-current')).toBe('page')
  })

  it('opens the workshop when no industry page was visited yet', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard', { storedModuleLastPage: JSON.stringify({ mining: '/mining/moons' }) })

    expect(industryLink(wrapper)?.attributes('href')).toBe(DEFAULT_INDUSTRY_PAGE)
  })
})
