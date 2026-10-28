import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { mountMainLayoutAt } from './mainLayoutHarness'

const OPEN_PALETTE_BUTTON_LABEL = 'Open command palette (Ctrl+K)'
const OPEN_SETTINGS_TITLE = 'Open settings'
const SETTINGS_TITLE = 'Settings'

function searchInput(): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>('[role="combobox"]')
}

function openSearchInput(): HTMLInputElement {
  const input = searchInput()
  expect(input, 'command palette search input').not.toBeNull()
  return input as HTMLInputElement
}

function isPaletteOpen(): boolean {
  return searchInput() !== null
}

function activeOptionLabel(): string | null {
  const activeOption = document.querySelector('[role="listbox"] [role="option"][aria-selected="true"]')
  return activeOption ? (activeOption.textContent ?? '').replace(/\s+/g, ' ').trim() : null
}

async function pressKeyOn(target: EventTarget, key: string, init: KeyboardEventInit = {}): Promise<KeyboardEvent> {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  target.dispatchEvent(event)
  await flushPromises()
  return event
}

async function pressCtrlK(target: EventTarget = document.activeElement ?? document.body): Promise<KeyboardEvent> {
  return pressKeyOn(target, 'k', { ctrlKey: true })
}

async function typeQuery(query: string): Promise<void> {
  const input = openSearchInput()
  input.value = query
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

function addPageInput(value: string): HTMLInputElement {
  const pageInput = document.createElement('input')
  pageInput.id = 'page-filter'
  pageInput.value = value
  document.body.appendChild(pageInput)
  pageInput.focus()
  return pageInput
}

function openPaletteButton(wrapper: VueWrapper): HTMLButtonElement | undefined {
  return wrapper.findAll('header button')
    .map(button => button.element as HTMLButtonElement)
    .find(button => button.getAttribute('aria-label') === OPEN_PALETTE_BUTTON_LABEL)
}

function settingsDialog(): HTMLElement | undefined {
  return Array.from(document.querySelectorAll<HTMLElement>('[role="dialog"]'))
    .find(dialog => dialog.querySelector('[role="combobox"]') === null && (dialog.textContent ?? '').includes(SETTINGS_TITLE))
}

enableAutoUnmount(afterEach)

describe('MainLayout command palette', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  describe('keyboard shortcut', () => {
    it('opens on Ctrl+K from the page body, focuses the search input and prevents the browser action', async () => {
      await mountMainLayoutAt('/dashboard')
      document.body.focus()

      const event = await pressCtrlK(document.body)

      expect(isPaletteOpen()).toBe(true)
      expect(document.activeElement).toBe(searchInput())
      expect(event.defaultPrevented).toBe(true)
    })

    it('opens on Cmd+K', async () => {
      await mountMainLayoutAt('/dashboard')

      await pressKeyOn(document.body, 'k', { metaKey: true })

      expect(isPaletteOpen()).toBe(true)
    })

    it('opens on Ctrl+K from a form field without altering its value', async () => {
      await mountMainLayoutAt('/dashboard')
      const pageInput = addPageInput('abc')

      const event = await pressCtrlK(pageInput)

      expect(isPaletteOpen()).toBe(true)
      expect(event.defaultPrevented).toBe(true)
      expect(pageInput.value).toBe('abc')
    })

    it('leaves a plain k to the focused form field', async () => {
      await mountMainLayoutAt('/dashboard')
      const pageInput = addPageInput('abc')

      const event = await pressKeyOn(pageInput, 'k')

      expect(isPaletteOpen()).toBe(false)
      expect(event.defaultPrevented).toBe(false)
      expect(document.activeElement).toBe(pageInput)
    })

    it.each([
      ['Ctrl+Shift+K', { key: 'K', ctrlKey: true, shiftKey: true }],
      ['Ctrl+Alt+K', { key: 'k', ctrlKey: true, altKey: true }],
    ])('does not open on %s', async (_shortcut, init) => {
      await mountMainLayoutAt('/dashboard')

      await pressKeyOn(document.body, init.key, init)

      expect(isPaletteOpen()).toBe(false)
    })

    it('closes on Ctrl+K when open and gives the focus back', async () => {
      await mountMainLayoutAt('/dashboard')
      const pageInput = addPageInput('abc')
      await pressCtrlK(pageInput)
      expect(isPaletteOpen()).toBe(true)

      await pressCtrlK()

      expect(isPaletteOpen()).toBe(false)
      expect(document.activeElement).toBe(pageInput)
    })

    it('reopens with an empty query and the first row active', async () => {
      await mountMainLayoutAt('/dashboard')
      await pressCtrlK(document.body)
      await typeQuery('buy')
      await pressCtrlK()

      await pressCtrlK(document.body)

      expect(searchInput()?.value).toBe('')
      expect(activeOptionLabel()).toBe('Dashboard')
    })
  })

  describe('header button', () => {
    it('opens the palette from a header button whose label carries the shortcut', async () => {
      const { wrapper } = await mountMainLayoutAt('/dashboard')
      expect(openPaletteButton(wrapper)).toBeDefined()

      openPaletteButton(wrapper)?.click()
      await flushPromises()

      expect(isPaletteOpen()).toBe(true)
    })

    it('gives the focus back to the header button on Escape', async () => {
      const { wrapper } = await mountMainLayoutAt('/dashboard')
      const button = openPaletteButton(wrapper) as HTMLButtonElement
      expect(button).toBeDefined()
      button.focus()
      button.click()
      await flushPromises()

      await pressKeyOn(openSearchInput(), 'Escape')

      expect(isPaletteOpen()).toBe(false)
      expect(document.activeElement).toBe(button)
    })
  })

  describe('above a screen modal', () => {
    it('opens over the settings modal and closes on Escape without closing it', async () => {
      const { wrapper } = await mountMainLayoutAt('/dashboard')
      await wrapper.find(`[title="${OPEN_SETTINGS_TITLE}"]`).trigger('click')
      await flushPromises()
      expect(settingsDialog()).toBeDefined()

      await pressCtrlK()
      expect(isPaletteOpen()).toBe(true)

      await pressKeyOn(openSearchInput(), 'Escape')

      expect(isPaletteOpen()).toBe(false)
      expect(settingsDialog()).toBeDefined()
    })
  })

  describe('navigation', () => {
    it('navigates to the selected entry and closes', async () => {
      const { router } = await mountMainLayoutAt('/dashboard')
      await pressCtrlK(document.body)
      await typeQuery('inv')

      await pressKeyOn(openSearchInput(), 'Enter')

      expect(router.currentRoute.value.fullPath).toBe('/assets')
      expect(isPaletteOpen()).toBe(false)
    })
  })
})
