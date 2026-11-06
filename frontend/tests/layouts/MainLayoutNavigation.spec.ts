import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { mountMainLayoutAt } from './mainLayoutHarness'

function navigationLink(wrapper: VueWrapper, label: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('a[href]').find(link => link.text().trim() === label)
}

function sectionHeader(wrapper: VueWrapper, label: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('nav button').find(button => button.text().trim() === label)
}

function sidebarLinkLabels(wrapper: VueWrapper): string[] {
  return wrapper.get('aside nav').findAll('a[href]').map(link => link.text().trim())
}

enableAutoUnmount(afterEach)

describe('MainLayout sidebar navigation', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('renders the dashboard entry as a link to its route', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Dashboard')?.attributes('href')).toBe('/dashboard')
  })

  it('renders each group child as a link to its route', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Industry')?.attributes('href')).toBe('/industry')
    expect(navigationLink(wrapper, 'Appraisal')?.attributes('href')).toBe('/appraisal')
    expect(navigationLink(wrapper, 'PI')?.attributes('href')).toBe('/planetary')
    expect(navigationLink(wrapper, 'Mining operations')?.attributes('href')).toBe('/mining-operations')
    expect(navigationLink(wrapper, 'Market')?.attributes('href')).toBe('/market')
    expect(navigationLink(wrapper, 'Revenue')?.attributes('href')).toBe('/ledger')
    expect(navigationLink(wrapper, 'Inventory')?.attributes('href')).toBe('/assets')
  })

  it('lists the sections of the V3 mockup in order, with no group industry entry', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    expect(sidebarLinkLabels(wrapper)).toEqual([
      'Dashboard',
      'Industry',
      'Appraisal',
      'PI',
      'Mining operations',
      'Market',
      'Revenue',
      'Inventory',
      'Characters',
    ])
    expect(wrapper.get('aside nav').findAll('button, p').map(heading => heading.text().trim())).toEqual([
      'Production',
      'Mining',
      'Economy',
      'Account',
      'Settings',
    ])
  })

  it('renders the characters entry as a link to the characters route', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Characters')?.attributes('href')).toBe('/characters')
  })

  it('marks only the entry of the current route with aria-current page', async () => {
    const { wrapper } = await mountMainLayoutAt('/industry')

    const currentPageLinks = wrapper.findAll('a[aria-current="page"]')

    expect(currentPageLinks).toHaveLength(1)
    expect(currentPageLinks[0].attributes('href')).toBe('/industry')
    expect(navigationLink(wrapper, 'Dashboard')?.attributes('aria-current')).toBeUndefined()
  })

  it.each(['/group-industry', '/group-industry/p1'])('keeps the industry entry current and titles the page Industry on %s', async (path) => {
    const { wrapper } = await mountMainLayoutAt(path)

    const currentPageLinks = wrapper.findAll('a[aria-current="page"]')

    expect(currentPageLinks.map(link => link.attributes('href'))).toEqual(['/industry'])
    expect(wrapper.get('header h2').text()).toBe('Industry')
  })

  it('keeps the production section open on a project page even when it was stored as collapsed', async () => {
    const { wrapper } = await mountMainLayoutAt('/group-industry/p1', { storedCollapsedGroups: '{"production":true}' })

    expect(sectionHeader(wrapper, 'Production')?.attributes('aria-expanded')).toBe('true')
    expect(navigationLink(wrapper, 'Industry')).toBeDefined()
  })

  it('keeps each collapsible section header as a button reflecting its expanded state', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    for (const [section, child] of [['Production', 'Industry'], ['Economy', 'Market']]) {
      expect(sectionHeader(wrapper, section)?.element.tagName).toBe('BUTTON')
      expect(sectionHeader(wrapper, section)?.attributes('aria-expanded')).toBe('true')

      await sectionHeader(wrapper, section)?.trigger('click')

      expect(sectionHeader(wrapper, section)?.attributes('aria-expanded')).toBe('false')
      expect(navigationLink(wrapper, child)).toBeUndefined()
    }
  })

  it('offers the remaining modules and no group industry module in the settings', async () => {
    const { wrapper } = await mountMainLayoutAt('/dashboard')

    await wrapper.findAll('aside nav button').find(button => button.text().trim() === 'Settings')?.trigger('click')
    await flushPromises()

    const moduleLabels = Array.from(document.querySelectorAll('[role="dialog"] label')).map(label => label.textContent?.trim())
    expect(moduleLabels).toEqual(['Dashboard', 'Industry', 'Appraisal', 'PI', 'Mining operations', 'Market', 'Revenue', 'Inventory'])
  })
})
