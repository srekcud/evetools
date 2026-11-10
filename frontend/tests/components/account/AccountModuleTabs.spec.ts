import { enableAutoUnmount } from '@vue/test-utils'
import AccountModuleTabs, { type AccountModulePage } from '@/components/account/AccountModuleTabs.vue'
import { mountModuleTabs } from '../layout/moduleTabsHarness'

const PAGES: Array<[AccountModulePage, string, string]> = [
  ['characters', '/characters', 'Characters'],
  ['settings', '/settings', 'Settings'],
]

enableAutoUnmount(afterEach)

describe('AccountModuleTabs', () => {
  it('links Characters then Settings under a navigation named Account and offers no settings shortcut', async () => {
    const wrapper = await mountModuleTabs(AccountModuleTabs, { active: 'characters' })

    expect(wrapper.find('nav').attributes('aria-label')).toBe('Account')
    expect(wrapper.findAll('a').map(link => [link.attributes('href'), link.text()])).toEqual(PAGES.map(([, path, label]) => [path, label]))
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountModuleTabs(AccountModuleTabs, { active })

    expect(wrapper.findAll('a[aria-current="page"]').map(link => link.attributes('href'))).toEqual([path])
  })
})
