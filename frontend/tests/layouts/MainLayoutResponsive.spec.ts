import { DOMWrapper, enableAutoUnmount, flushPromises } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { mountMainLayoutAt, PAGE_CONTENT_SELECTOR, setViewportWidth } from './mainLayoutHarness'

const PHONE_WIDTH = 375
const MENU_BUTTON_SELECTOR = 'header button[aria-controls]'
const BACKDROP_SELECTOR = '[data-testid="navigation-drawer-backdrop"]'
const ALL_NAVIGATION_LABELS = ['Dashboard', 'Revenue', 'Industry', 'Group Industry', 'Appraisal', 'PI', 'Market', 'Inventory', 'Characters']
const NAVIGATION_LABELS_WITH_PRODUCTION_COLLAPSED = ['Dashboard', 'Revenue', 'Market', 'Inventory', 'Characters']

function isDisplayed(element: Element): boolean {
  return new DOMWrapper(element).isVisible()
}

function persistentSidebar(): Element | undefined {
  return Array.from(document.querySelectorAll('aside'))
    .find(aside => aside.closest('[role="dialog"]') === null && isDisplayed(aside))
}

function menuButton(): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>(MENU_BUTTON_SELECTOR)
}

function openDrawer(): HTMLElement | null {
  const drawerId = menuButton()?.getAttribute('aria-controls')
  if (!drawerId) {
    return null
  }
  const drawer = document.getElementById(drawerId)
  return drawer !== null && isDisplayed(drawer) ? drawer : null
}

function drawerNavigationLabels(): string[] {
  const drawer = openDrawer()
  return drawer === null ? [] : Array.from(drawer.querySelectorAll('a[href]')).map(link => link.textContent?.trim() ?? '')
}

function drawerNavigationLink(label: string): HTMLAnchorElement | undefined {
  const drawer = openDrawer()
  return drawer === null
    ? undefined
    : Array.from(drawer.querySelectorAll<HTMLAnchorElement>('a[href]')).find(link => link.textContent?.trim() === label)
}

function drawerGroupHeader(label: string): HTMLButtonElement | undefined {
  const drawer = openDrawer()
  return drawer === null
    ? undefined
    : Array.from(drawer.querySelectorAll<HTMLButtonElement>('button')).find(button => button.textContent?.trim() === label)
}

function isInert(selector: string): boolean {
  const element = document.querySelector(selector)
  return element !== null && element.closest('[inert]') !== null
}

function settingsModal(): Element | undefined {
  return Array.from(document.querySelectorAll('[role="dialog"]')).find(dialog => {
    const titleId = dialog.getAttribute('aria-labelledby')
    return titleId !== null && document.getElementById(titleId)?.textContent?.trim() === en.settings.title
  })
}

async function click(element: Element | null | undefined): Promise<void> {
  expect(element ?? null, 'element to click').not.toBeNull()
  if (!element) {
    return
  }
  await new DOMWrapper(element).trigger('click')
  await flushPromises()
}

async function pressEscape(): Promise<void> {
  const target = document.activeElement ?? document.body
  target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  await flushPromises()
}

async function resizeViewportTo(width: number): Promise<void> {
  window.dispatchEvent(new Event('resize'))
  setViewportWidth(width)
  await flushPromises()
}

async function mountOpenDrawerAt(path: string, storedCollapsedGroups?: string) {
  const mounted = await mountMainLayoutAt(path, { viewportWidth: PHONE_WIDTH, storedCollapsedGroups })
  await click(menuButton())
  return mounted
}

enableAutoUnmount(afterEach)

describe('MainLayout responsive navigation', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it.each([1440, 1024])('keeps the persistent sidebar without menu button nor drawer at %i px', async (viewportWidth) => {
    await mountMainLayoutAt('/dashboard', { viewportWidth })

    expect(persistentSidebar()).toBeDefined()
    expect(menuButton()).toBeNull()
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(0)
  })

  it.each([1023, 768, 375])('replaces the sidebar with a collapsed menu button at %i px', async (viewportWidth) => {
    await mountMainLayoutAt('/dashboard', { viewportWidth })

    expect(persistentSidebar()).toBeUndefined()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(openDrawer()).toBeNull()
  })

  it('opens a modal drawer holding the whole navigation and makes the page inert', async () => {
    await mountOpenDrawerAt('/dashboard')

    const drawer = openDrawer()

    expect(menuButton()?.getAttribute('aria-expanded')).toBe('true')
    expect(drawer?.getAttribute('role')).toBe('dialog')
    expect(drawer?.getAttribute('aria-modal')).toBe('true')
    expect(drawerNavigationLabels()).toEqual(ALL_NAVIGATION_LABELS)
    expect(persistentSidebar()).toBeUndefined()
    expect(isInert('header')).toBe(true)
    expect(isInert(PAGE_CONTENT_SELECTOR)).toBe(true)
    expect(document.activeElement?.tagName).toBe('BUTTON')
    expect(drawer?.contains(document.activeElement)).toBe(true)
    expect(document.activeElement?.textContent?.trim()).not.toBe('Production')
  })

  it('closes the drawer with its close button and gives the focus back to the menu button', async () => {
    await mountOpenDrawerAt('/dashboard')

    await click(document.activeElement)

    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(menuButton())
  })

  it('closes the drawer when a navigation entry is clicked and navigates to its route', async () => {
    const { router } = await mountOpenDrawerAt('/dashboard')

    await click(drawerNavigationLink('Industry'))

    expect(router.currentRoute.value.path).toBe('/industry')
    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(persistentSidebar()).toBeUndefined()
  })

  it('closes the drawer when the entry of the current route is clicked', async () => {
    const { router } = await mountOpenDrawerAt('/dashboard')

    await click(drawerNavigationLink('Dashboard'))

    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
  })

  it('closes the drawer on a backdrop click, gives the focus back to the menu button and lifts inert', async () => {
    await mountOpenDrawerAt('/dashboard')

    await click(document.querySelector(BACKDROP_SELECTOR))

    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(menuButton())
    expect(isInert('header')).toBe(false)
    expect(isInert(PAGE_CONTENT_SELECTOR)).toBe(false)
  })

  it('closes the drawer on Escape and gives the focus back to the menu button', async () => {
    await mountOpenDrawerAt('/dashboard')

    await pressEscape()

    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(menuButton())
  })

  it('ignores Escape while the drawer is closed', async () => {
    const { router } = await mountMainLayoutAt('/dashboard', { viewportWidth: PHONE_WIDTH })
    const consoleError = vi.spyOn(console, 'error')

    await pressEscape()

    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(consoleError).not.toHaveBeenCalled()
  })

  it('closes the drawer on a programmatic route change', async () => {
    const { router } = await mountOpenDrawerAt('/dashboard')

    await router.push('/market')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/market')
    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
  })

  it('keeps the drawer open when the Production group is collapsed and persists the collapsed group', async () => {
    await mountOpenDrawerAt('/dashboard')

    await click(drawerGroupHeader('Production'))

    expect(menuButton()?.getAttribute('aria-expanded')).toBe('true')
    expect(drawerGroupHeader('Production')?.getAttribute('aria-expanded')).toBe('false')
    expect(drawerNavigationLabels()).toEqual(NAVIGATION_LABELS_WITH_PRODUCTION_COLLAPSED)
    expect(localStorage.getItem('evetools_nav_collapsed')).toBe('{"production":true}')
  })

  it('closes the drawer and restores the sidebar when widened past the navigation breakpoint', async () => {
    await mountOpenDrawerAt('/dashboard')

    await resizeViewportTo(1280)

    expect(persistentSidebar()).toBeDefined()
    expect(menuButton()).toBeNull()
    expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(0)
    expect(isInert('header')).toBe(false)
    expect(isInert(PAGE_CONTENT_SELECTOR)).toBe(false)
  })

  it('replaces the sidebar with a closed drawer when narrowed below the navigation breakpoint', async () => {
    await mountMainLayoutAt('/dashboard', { viewportWidth: 1280 })

    await resizeViewportTo(800)

    expect(persistentSidebar()).toBeUndefined()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(openDrawer()).toBeNull()
  })

  it('hides the children of a stored collapsed group in the drawer', async () => {
    await mountOpenDrawerAt('/dashboard', '{"production":true}')

    expect(menuButton()?.getAttribute('aria-expanded')).toBe('true')
    expect(drawerNavigationLabels()).toEqual(NAVIGATION_LABELS_WITH_PRODUCTION_COLLAPSED)
  })

  it('expands a stored collapsed group in the drawer when one of its children is the current route', async () => {
    await mountOpenDrawerAt('/industry', '{"production":true}')

    expect(menuButton()?.getAttribute('aria-expanded')).toBe('true')
    expect(drawerGroupHeader('Production')?.getAttribute('aria-expanded')).toBe('true')
    expect(drawerNavigationLabels()).toEqual(ALL_NAVIGATION_LABELS)
  })

  it('closes the drawer and opens the settings modal from the character block', async () => {
    await mountOpenDrawerAt('/dashboard')

    await click(openDrawer()?.querySelector(`[title="${en.auth.openSettings}"]`))

    expect(openDrawer()).toBeNull()
    expect(menuButton()?.getAttribute('aria-expanded')).toBe('false')
    expect(settingsModal()).toBeDefined()
  })
})
