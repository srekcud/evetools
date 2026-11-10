import { enableAutoUnmount } from '@vue/test-utils'
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { mountMainLayoutAt } from './mainLayoutHarness'

const DEFAULT_MINING_PAGE = '/mining/operations'
const LAST_VISITED_MOONS_PAGE = '/mining/moons'

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
