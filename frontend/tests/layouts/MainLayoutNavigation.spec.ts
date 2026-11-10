import { enableAutoUnmount } from '@vue/test-utils'
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { MINING_SITE_PATH, mountMainLayoutAt } from './mainLayoutHarness'

const MODULE_LINKS = [
  ['Home', '/dashboard'],
  ['Industry', '/industry'],
  ['Mining', '/mining/operations'],
  ['PVE', '/pve/revenue'],
  ['Planetary', '/planetary'],
  ['Commerce', '/market'],
  ['Wealth', '/balance'],
  ['Account', '/characters'],
]

const NAV_COLLAPSED_STORAGE_KEY = 'evetools_nav_collapsed'

function navigationLink(wrapper: VueWrapper, label: string): DOMWrapper<Element> | undefined {
  return wrapper.get('aside nav').findAll('a[href]').find(link => link.text().trim() === label)
}

function sidebarLinks(wrapper: VueWrapper): string[][] {
  return wrapper.get('aside nav').findAll('a[href]').map(link => [link.text().trim(), link.attributes('href') ?? ''])
}

function currentModuleLabels(wrapper: VueWrapper): string[] {
  return wrapper.get('aside nav').findAll('a[aria-current="page"]').map(link => link.text().trim())
}

enableAutoUnmount(afterEach)

describe('MainLayout sidebar navigation', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists the eight modules in order as plain links, with no collapsible group, no account block and no settings button', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    expect(sidebarLinks(wrapper)).toEqual(MODULE_LINKS)
    expect(wrapper.get('aside nav').findAll('button, p')).toHaveLength(0)
    expect(wrapper.find('[title="Open settings"]').exists()).toBe(false)
  })

  it('adds the administration entry as the ninth link for an administrator only', async () => {
    const administrator = await mountMainLayoutAt('/dashboard', { isAdministrator: true })
    const administratorLinks = sidebarLinks(administrator.wrapper)
    administrator.wrapper.unmount()

    const member = await mountMainLayoutAt('/dashboard')

    expect(administratorLinks).toHaveLength(9)
    expect(administratorLinks[8]).toEqual(['Administration', '/admin'])
    expect(sidebarLinks(member.wrapper)).toHaveLength(8)
  })

  it('points each module at its last visited page and falls back to the module route', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard', {
      storedModuleLastPage: JSON.stringify({ commerce: '/contracts', patrimoine: '/assets', account: '/settings' }),
    })

    expect(navigationLink(wrapper, 'Commerce')?.attributes('href')).toBe('/contracts')
    expect(navigationLink(wrapper, 'Wealth')?.attributes('href')).toBe('/assets')
    expect(navigationLink(wrapper, 'Account')?.attributes('href')).toBe('/settings')
    expect(navigationLink(wrapper, 'Industry')?.attributes('href')).toBe('/industry')
  })

  it.each([
    ['a mining site detail', MINING_SITE_PATH, 'Mining'],
    ['a project detail', '/industry/projects/p1', 'Industry'],
    ['the escalations page', '/pve/escalations', 'PVE'],
    ['a settings section', '/settings/industry', 'Account'],
  ])('marks only the module of %s as the current page', async (_case, path, moduleLabel) => {
    const { wrapper } = await mountMainLayoutAt(path)

    expect(currentModuleLabels(wrapper)).toEqual([moduleLabel])
  })

  it.each([
    ['the home page', '/dashboard', 'Home'],
    ['the planetary page', '/planetary', 'Planetary'],
    ['a project detail', '/industry/projects/p1', 'Industry › Projects'],
    ['the inventory', '/assets', 'Wealth › Inventory'],
  ])('titles the header with the module then the page on %s', async (_case, path, expectedTitle) => {
    const { wrapper } = await mountMainLayoutAt(path)

    expect(wrapper.get('header h2').text()).toBe(expectedTitle)
  })

  it('neither reads nor writes the former collapsed groups key', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard', { storedCollapsedGroups: '{"production":true,"economy":true}' })

    expect(sidebarLinks(wrapper)).toEqual(MODULE_LINKS)
    expect(localStorage.getItem(NAV_COLLAPSED_STORAGE_KEY)).toBe('{"production":true,"economy":true}')
  })

  it('hides a module from the sidebar when the legacy ids of its pages are stored as hidden', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard', { storedHiddenModules: ['market', 'appraisal', 'ledger', 'assets'] })

    expect(sidebarLinks(wrapper).map(([label]) => label)).toEqual(['Home', 'Industry', 'Mining', 'PVE', 'Planetary', 'Account'])
  })
})
