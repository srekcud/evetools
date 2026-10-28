import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CommandPalette from '@/components/common/CommandPalette.vue'

type Locale = 'en' | 'fr'

interface PaletteMountOptions {
  locale?: Locale
  isAdmin?: boolean
  hiddenModules?: string[]
  storedHiddenModules?: string
}

const HIDDEN_MODULES_STORAGE_KEY = 'evetools_hidden_modules'
const STARTING_ROUTE = '/characters'
const BACKDROP_SELECTOR = '[data-testid="command-palette-backdrop"]'

const appRoutes = [
  '/dashboard',
  '/ledger',
  '/industry',
  '/group-industry',
  '/appraisal',
  '/planetary',
  '/market',
  '/assets',
  '/characters',
  '/admin',
]

async function mountOpenPalette({ locale = 'en', isAdmin = false, hiddenModules, storedHiddenModules }: PaletteMountOptions = {}) {
  localStorage.clear()
  if (hiddenModules !== undefined) {
    localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(hiddenModules))
  }
  if (storedHiddenModules !== undefined) {
    localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, storedHiddenModules)
  }
  const router: Router = createRouter({
    history: createMemoryHistory(),
    routes: appRoutes.map(route => ({ path: route, component: { template: '<div />' } })),
  })
  await router.push(STARTING_ROUTE)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  let closeFromParent: () => Promise<void> = async () => {}
  const wrapper = mount(CommandPalette, {
    attachTo: document.body,
    props: { show: false, isAdmin, onClose: () => closeFromParent() },
    global: { plugins: [i18n, router] },
  })
  closeFromParent = async () => {
    await wrapper.setProps({ show: false })
  }
  const reopen = async () => {
    await wrapper.setProps({ show: true })
    await flushPromises()
  }
  await reopen()
  return { wrapper, router, reopen }
}

function searchInput(): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>('[role="combobox"]')
}

function openSearchInput(): HTMLInputElement {
  const input = searchInput()
  expect(input, 'command palette search input').not.toBeNull()
  return input as HTMLInputElement
}

function paletteDialog(): HTMLElement | null {
  return searchInput()?.closest<HTMLElement>('[role="dialog"]') ?? null
}

function paletteText(): string {
  const dialog = paletteDialog()
  return dialog ? visibleText(dialog) : ''
}

function optionElements(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('[role="listbox"] [role="option"]'))
}

function visibleText(element: Element): string {
  return (element.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function optionLabels(): string[] {
  return optionElements().map(visibleText)
}

function activeOptionLabel(): string | null {
  const activeOptions = optionElements().filter(option => option.getAttribute('aria-selected') === 'true')
  return activeOptions.length === 1 ? visibleText(activeOptions[0]) : null
}

function accessibleName(element: Element): string {
  const labelledBy = element.getAttribute('aria-labelledby')
  if (labelledBy) {
    return visibleText(document.getElementById(labelledBy) as HTMLElement)
  }
  return element.getAttribute('aria-label') ?? ''
}

async function typeQuery(query: string): Promise<void> {
  const input = openSearchInput()
  input.value = query
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

async function pressKey(key: string, init: KeyboardEventInit = {}): Promise<KeyboardEvent> {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  openSearchInput().dispatchEvent(event)
  await flushPromises()
  return event
}

async function pressArrowDown(times: number): Promise<void> {
  for (let press = 0; press < times; press++) {
    await pressKey('ArrowDown')
  }
}

function addPageInput(value: string): HTMLInputElement {
  const pageInput = document.createElement('input')
  pageInput.id = 'page-search'
  pageInput.value = value
  document.body.appendChild(pageInput)
  pageInput.focus()
  return pageInput
}

enableAutoUnmount(afterEach)

describe('CommandPalette', () => {
  afterEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
  })

  describe('opening state', () => {
    it('focuses an empty search input and activates the first row', async () => {
      await mountOpenPalette()

      expect(document.activeElement).toBe(searchInput())
      expect(searchInput()?.value).toBe('')
      expect(activeOptionLabel()).toBe('Dashboard')
    })

    it('exposes a modal dialog with a combobox controlling a listbox of grouped options', async () => {
      await mountOpenPalette()
      await pressArrowDown(1)

      const input = openSearchInput()
      const listbox = document.querySelector('[role="listbox"]') as HTMLElement
      expect(listbox).not.toBeNull()
      const activeOption = optionElements()[1]
      const groups = Array.from(listbox.querySelectorAll('[role="group"]'))

      expect(paletteDialog()?.getAttribute('aria-modal')).toBe('true')
      expect(input.getAttribute('aria-expanded')).toBe('true')
      expect(input.getAttribute('aria-controls')).toBe(listbox.id)
      expect(listbox.id).not.toBe('')
      expect(activeOption.id).not.toBe('')
      expect(input.getAttribute('aria-activedescendant')).toBe(activeOption.id)
      expect(optionElements().map(option => option.getAttribute('aria-selected'))).toEqual([
        'false', 'true', 'false', 'false', 'false', 'false', 'false', 'false', 'false',
      ])
      expect(groups.map(accessibleName)).toEqual(['Navigation'])
    })
  })

  describe('navigation rows', () => {
    it('lists only the screens, in sidebar order, for an empty query', async () => {
      await mountOpenPalette()

      expect(optionLabels()).toEqual([
        'Dashboard',
        'Revenue',
        'Industry',
        'Group Industry',
        'Appraisal',
        'PI',
        'Market',
        'Inventory',
        'Characters',
      ])
    })

    it('adds the admin screen last for an administrator', async () => {
      await mountOpenPalette({ isAdmin: true })

      expect(optionLabels()).toEqual([
        'Dashboard',
        'Revenue',
        'Industry',
        'Group Industry',
        'Appraisal',
        'PI',
        'Market',
        'Inventory',
        'Characters',
        'Administration',
      ])
    })

    it.each([
      ['buy'],
      ['  BUY  '],
      ['ind buy'],
    ])('finds the Buy vs Build tab for the query %j', async (query) => {
      await mountOpenPalette()

      await typeQuery(query)

      expect(optionLabels()).toEqual(['Industry › Buy vs Build'])
    })

    it('lists Industry and its tabs before Group Industry for ind, ten rows at most', async () => {
      await mountOpenPalette()

      await typeQuery('ind')

      expect(optionLabels()).toEqual([
        'Industry',
        'Industry › Projects',
        'Industry › Margins',
        'Industry › Batch Scan',
        'Industry › Buy vs Build',
        'Industry › Pivot',
        'Industry › Slots',
        'Industry › Stockpile',
        'Group Industry',
        'Group Industry › Available',
      ])
    })

    it('ranks every entry containing a by rank then by sidebar order', async () => {
      await mountOpenPalette()

      await typeQuery('a')

      expect(optionLabels()).toEqual([
        'Appraisal',
        'Group Industry › Available',
        'Market › Alerts',
        'Dashboard',
        'Revenue › Escalations',
        'Industry › Margins',
        'Industry › Batch Scan',
        'Market',
        'Inventory › Contracts',
        'Characters',
      ])
    })

    it('does not offer the admin screen to a user who is not an administrator', async () => {
      await mountOpenPalette()

      await typeQuery('admin')

      expect(optionLabels()).toEqual([])
    })

    it('does not match the sidebar group name', async () => {
      await mountOpenPalette()

      await typeQuery('production')

      expect(optionLabels()).toEqual([])
    })

    it('shows French labels found through their English alias', async () => {
      await mountOpenPalette({ locale: 'fr' })

      await typeQuery('buy')
      expect(optionLabels()).toEqual(['Industrie › Achat vs Prod'])

      await typeQuery('contracts')
      expect(optionLabels()).toEqual(['Inventaire › Contrats'])
    })

    it('finds unaccented French labels from an accented query', async () => {
      await mountOpenPalette({ locale: 'fr' })

      await typeQuery('marché')

      expect(optionLabels()).toEqual(['Marche', 'Marche › Alertes'])
    })
  })

  describe('hidden modules', () => {
    it('removes the tabs of a hidden module and reports no result', async () => {
      await mountOpenPalette({ hiddenModules: ['industry'] })

      await typeQuery('buy')

      expect(optionLabels()).toEqual([])
      expect(document.querySelectorAll('[role="listbox"] [role="group"]')).toHaveLength(0)
      expect(paletteText()).toContain('No results for "buy"')
    })

    it('hides no module when the stored hidden modules are not readable', async () => {
      await mountOpenPalette({ storedHiddenModules: 'pas du json' })

      await typeQuery('buy')

      expect(optionLabels()).toEqual(['Industry › Buy vs Build'])
    })

    it('reads the hidden modules again at the next opening', async () => {
      const { reopen } = await mountOpenPalette()
      expect(optionLabels()).toContain('Market')
      await pressKey('Escape')

      localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(['market']))
      await reopen()

      expect(optionLabels()).toEqual([
        'Dashboard',
        'Revenue',
        'Industry',
        'Group Industry',
        'Appraisal',
        'PI',
        'Inventory',
        'Characters',
      ])
    })
  })

  describe('no result', () => {
    it('reports no result for a query matching nothing', async () => {
      await mountOpenPalette()

      await typeQuery('zzz')

      expect(optionLabels()).toEqual([])
      expect(paletteText()).toContain('No results for "zzz"')
    })

    it('ignores Enter when there is no row and stays open', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQuery('zzz')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe(STARTING_ROUTE)
      expect(wrapper.emitted('close')).toBeUndefined()
      expect(searchInput()).not.toBeNull()
    })
  })

  describe('execution', () => {
    it('navigates to the active tab on Enter and closes', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQuery('buy')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=buy-vs-build')
      expect(wrapper.emitted('close')).toEqual([[]])
      expect(searchInput()).toBeNull()
    })

    it('navigates to the row reached with the arrows on Enter', async () => {
      const { router } = await mountOpenPalette()
      await typeQuery('inv')
      await pressArrowDown(1)

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/assets?tab=contracts')
    })

    it('navigates to a screen without a tab query', async () => {
      const { router } = await mountOpenPalette()
      await pressArrowDown(6)

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/market')
    })

    it('navigates to a clicked row and closes', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQuery('ind')
      expect(optionLabels()[2]).toBe('Industry › Margins')

      optionElements()[2].click()
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=margins')
      expect(wrapper.emitted('close')).toEqual([[]])
    })
  })

  describe('keyboard', () => {
    it('moves the active row down with ArrowDown and updates the active descendant', async () => {
      await mountOpenPalette()
      await typeQuery('ind')

      await pressArrowDown(2)

      expect(activeOptionLabel()).toBe('Industry › Margins')
      expect(searchInput()?.getAttribute('aria-activedescendant')).toBe(optionElements()[2].id)
    })

    it('wraps ArrowUp from the first row to the last', async () => {
      await mountOpenPalette()
      await typeQuery('ind')

      await pressKey('ArrowUp')

      expect(activeOptionLabel()).toBe('Group Industry › Available')
    })

    it('wraps ArrowDown from the last row to the first', async () => {
      await mountOpenPalette()
      await typeQuery('ind')
      await pressArrowDown(9)
      expect(activeOptionLabel()).toBe('Group Industry › Available')

      await pressKey('ArrowDown')

      expect(activeOptionLabel()).toBe('Industry')
    })

    it('activates the first row again when the query changes', async () => {
      await mountOpenPalette()
      await typeQuery('ind')
      await pressArrowDown(2)

      await typeQuery('indu')

      expect(activeOptionLabel()).toBe('Industry')
    })

    it('activates a row hovered with the mouse', async () => {
      await mountOpenPalette()
      const hoveredOption = optionElements()[3]
      expect(hoveredOption).toBeDefined()

      hoveredOption.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }))
      hoveredOption.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }))
      await flushPromises()

      expect(activeOptionLabel()).toBe('Group Industry')
    })

    it.each([
      ['Tab', false],
      ['Shift+Tab', true],
    ])('keeps the focus in the search input on %s', async (_label, shiftKey) => {
      await mountOpenPalette()

      const event = await pressKey('Tab', { shiftKey })

      expect(event.defaultPrevented).toBe(true)
      expect(document.activeElement).toBe(searchInput())
    })
  })

  describe('closing', () => {
    it('closes on Escape without letting a document listener receive it', async () => {
      const documentKeydown = vi.fn()
      document.addEventListener('keydown', documentKeydown)
      const { wrapper } = await mountOpenPalette()

      await pressKey('Escape')
      document.removeEventListener('keydown', documentKeydown)

      expect(wrapper.emitted('close')).toEqual([[]])
      expect(documentKeydown).not.toHaveBeenCalled()
      expect(searchInput()).toBeNull()
    })

    it('gives the focus back to the element focused before opening', async () => {
      const pageInput = addPageInput('abc')
      await mountOpenPalette()
      expect(document.activeElement).toBe(searchInput())

      await pressKey('Escape')

      expect(document.activeElement).toBe(pageInput)
      expect(pageInput.value).toBe('abc')
    })

    it('closes on a click on the backdrop', async () => {
      const { wrapper } = await mountOpenPalette()
      await typeQuery('buy')

      const backdrop = document.querySelector<HTMLElement>(BACKDROP_SELECTOR)
      expect(backdrop).not.toBeNull()
      backdrop?.click()
      await flushPromises()

      expect(wrapper.emitted('close')).toEqual([[]])
      expect(searchInput()).toBeNull()
    })

    it('reopens with an empty query and the first row active', async () => {
      const { reopen } = await mountOpenPalette()
      await typeQuery('buy')
      await pressKey('Escape')

      await reopen()

      expect(searchInput()?.value).toBe('')
      expect(activeOptionLabel()).toBe('Dashboard')
    })
  })
})
