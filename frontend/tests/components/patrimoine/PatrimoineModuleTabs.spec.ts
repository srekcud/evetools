import { enableAutoUnmount } from '@vue/test-utils'
import PatrimoineModuleTabs, { type PatrimoineModulePage } from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import { mountModuleTabs } from '../layout/moduleTabsHarness'

const PAGES: Array<[PatrimoineModulePage, string, string]> = [
  ['balance', '/balance', 'Balance'],
  ['assets', '/assets', 'Inventory'],
]

enableAutoUnmount(afterEach)

describe('PatrimoineModuleTabs', () => {
  it('links the two pages of the module in order, labelled Balance and Inventory, under a navigation named Wealth', async () => {
    const wrapper = await mountModuleTabs(PatrimoineModuleTabs, { active: 'balance' })

    expect(wrapper.find('nav').attributes('aria-label')).toBe('Wealth')
    expect(wrapper.findAll('a[title]').map(link => [link.attributes('href'), link.text()])).toEqual(PAGES.map(([, path, label]) => [path, label]))
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountModuleTabs(PatrimoineModuleTabs, { active })

    const currentLinks = wrapper.findAll('a[aria-current="page"]')

    expect(currentLinks.map(link => link.attributes('href'))).toEqual([path])
  })
})
