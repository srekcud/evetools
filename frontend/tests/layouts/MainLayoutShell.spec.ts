import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { useAuthStore } from '@/stores/auth'
import { useRateLimitStore } from '@/stores/rateLimit'
import type { Character } from '@/types'
import { mountMainLayoutAt } from './mainLayoutHarness'

const MAIN_CHARACTER: Character = {
  id: 'character-1',
  eveCharacterId: 2112000001,
  name: 'Alexandrine Vasquez-Okonkwo Delacroix',
  corporationId: 98000001,
  corporationName: 'Interstellar Kaalakiota Heavy Industries Logistics',
  allianceId: 99000001,
  allianceName: 'Fictional Alliance Of Long Names',
  isMain: true,
  hasValidToken: true,
  lastSyncAt: null,
}

enableAutoUnmount(afterEach)

async function mountWithMainCharacter(): Promise<VueWrapper> {
  const { wrapper } = await mountMainLayoutAt('/dashboard')
  useAuthStore().user = {
    id: 'user-1',
    authStatus: 'valid',
    mainCharacter: MAIN_CHARACTER,
    characters: [MAIN_CHARACTER],
    corporationId: MAIN_CHARACTER.corporationId,
    corporationName: MAIN_CHARACTER.corporationName,
  }
  await flushPromises()
  return wrapper
}

function accountButton(wrapper: VueWrapper) {
  return wrapper.get('header button[aria-haspopup="menu"]')
}

function accountMenu(): HTMLElement | null {
  return document.querySelector<HTMLElement>('[role="menu"]')
}

function accountMenuLabels(): string[] {
  return Array.from(accountMenu()?.querySelectorAll('[role="menuitem"]') ?? []).map(item => item.textContent?.trim() ?? '')
}

describe('MainLayout shell', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('header', () => {
    it('shows the page title without the welcome message nor alliance and corporation badges', async () => {
      const wrapper = await mountWithMainCharacter()
      const header = wrapper.get('header')

      expect(header.get('h2').text()).toBe('Home')
      expect(header.text()).not.toContain(en.header.welcomeBack)
      expect(header.text()).not.toContain(MAIN_CHARACTER.allianceName)
      expect(header.find('img[src*="/alliances/"]').exists()).toBe(false)
      expect(header.find('img[src*="/corporations/"]').exists()).toBe(false)
    })

    it('names the game server in the status indicator', async () => {
      const wrapper = await mountWithMainCharacter()

      expect(wrapper.get('header').text()).toContain('Tranquility')
    })

    it('shows the visible search shortcut', async () => {
      const wrapper = await mountWithMainCharacter()
      const searchButton = wrapper.findAll('header button').find(button => button.attributes('aria-label') === en.commandPalette.open)

      expect(searchButton?.text()).toContain('Ctrl K')
    })
  })

  describe('account menu', () => {
    it('shows the main character and corporation on the account button', async () => {
      const wrapper = await mountWithMainCharacter()

      expect(accountButton(wrapper).text()).toContain(MAIN_CHARACTER.name)
      expect(accountButton(wrapper).text()).toContain(MAIN_CHARACTER.corporationName)
    })

    it('opens a menu with settings, characters, release notes and logout', async () => {
      const wrapper = await mountWithMainCharacter()
      expect(accountButton(wrapper).attributes('aria-expanded')).toBe('false')
      expect(accountMenu()).toBeNull()

      await accountButton(wrapper).trigger('click')

      expect(accountButton(wrapper).attributes('aria-expanded')).toBe('true')
      expect(accountMenuLabels()).toEqual([en.settings.title, en.nav.characters, en.header.releaseNotes, en.auth.logout])
    })

    it('closes on Escape and gives the focus back to the account button', async () => {
      const wrapper = await mountWithMainCharacter()
      await accountButton(wrapper).trigger('click')

      accountMenu()?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
      await flushPromises()

      expect(accountMenu()).toBeNull()
      expect(document.activeElement).toBe(accountButton(wrapper).element)
    })

    it('links the settings item to the settings page instead of opening a modal', async () => {
      const wrapper = await mountWithMainCharacter()
      await accountButton(wrapper).trigger('click')

      const settingsItem = Array.from(accountMenu()?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
        .find(item => item.textContent?.trim() === en.settings.title)

      expect(settingsItem?.tagName).toBe('A')
      expect(settingsItem?.getAttribute('href')).toBe('/settings')
      settingsItem?.click()
      await flushPromises()
      expect(accountMenu()).toBeNull()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
    })
  })

  describe('sidebar', () => {
    it('reaches characters and settings through the Account module link and holds no settings button', async () => {
      const wrapper = await mountWithMainCharacter()
      const sidebar = wrapper.get('aside')

      expect(sidebar.findAll('a[href]').map(link => link.text().trim())).toContain('Account')
      expect(sidebar.findAll('button').map(button => button.text().trim())).not.toContain(en.settings.title)
    })
  })

  describe('calm by default', () => {
    it('runs no infinite decorative animation', async () => {
      const wrapper = await mountWithMainCharacter()

      expect(wrapper.findAll('[class*="animate-"]').map(element => element.classes().join(' '))).toEqual([])
    })
  })

  describe('rate limit banner', () => {
    it('is announced in the page flow instead of shifting the whole screen', async () => {
      const wrapper = await mountWithMainCharacter()

      useRateLimitStore().setRateLimited()
      await flushPromises()

      const banner = wrapper.get('main [role="status"]')
      expect(banner.text()).toContain('ESI')
      expect(banner.classes()).not.toContain('fixed')
      expect(wrapper.find('.pt-10').exists()).toBe(false)
    })
  })
})
