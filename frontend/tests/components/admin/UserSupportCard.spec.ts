import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import UserSupportCard from '@/components/admin/UserSupportCard.vue'
import type { AdminUserSearchHit, AdminUserSheet } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const USER_ID = '0196a1c0-0000-7000-8000-000000000001'

function hitOf(overrides: Partial<AdminUserSearchHit> = {}): AdminUserSearchHit {
  return {
    userId: USER_ID,
    mainCharacterName: 'Mira Tolland',
    mainEveCharacterId: 1001,
    characterCount: 2,
    lastLoginAt: '2026-10-20T12:30:00+00:00',
    ...overrides,
  }
}

function sheetOf(overrides: Partial<AdminUserSheet> = {}): AdminUserSheet {
  return {
    userId: USER_ID,
    createdAt: '2026-01-02T08:00:00+00:00',
    lastLoginAt: '2026-10-20T12:30:00+00:00',
    characters: [
      {
        eveCharacterId: 1001,
        name: 'Mira Tolland',
        isMain: true,
        tokenState: 'valid',
        accessTokenExpiresAt: '2026-11-11T12:20:00+00:00',
        missingScopes: [],
        lastSyncAt: '2026-11-11T11:00:00+00:00',
      },
      {
        eveCharacterId: 1002,
        name: 'Dax Orrin',
        isMain: false,
        tokenState: 'invalid',
        accessTokenExpiresAt: '2026-11-10T12:20:00+00:00',
        missingScopes: ['esi-assets.read_assets.v1'],
      },
    ],
    syncs: [
      { type: 'industry', lastDataAt: '2026-11-11T10:00:00+00:00', triggerable: true },
      { type: 'assets', triggerable: false },
    ],
    ...overrides,
  }
}

function mountCard(props: { selectedUserId?: string | null } = {}, locale: TestLocale = 'en') {
  return mount(UserSupportCard, {
    props: { selectedUserId: null, ...props },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

async function searchFor(wrapper: ReturnType<typeof mountCard>, term: string) {
  await wrapper.get('[data-testid="user-search-input"]').setValue(term)
  await wrapper.get('[data-testid="user-search-form"]').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('UserSupportCard', () => {
  it('searches the users by the typed term and lists the hits', async () => {
    vi.mocked(apiRequest).mockResolvedValue([hitOf(), hitOf({ userId: 'other', mainCharacterName: null, mainEveCharacterId: null, characterCount: 1, lastLoginAt: undefined })])
    const wrapper = mountCard()

    await searchFor(wrapper, 'mira')

    expect(apiRequest).toHaveBeenCalledWith('/admin/users?search=mira')
    const rows = wrapper.findAll('[data-testid="user-search-hit"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Mira Tolland')
  })

  it('does not call the API for a term shorter than two characters', async () => {
    const wrapper = mountCard()

    await searchFor(wrapper, ' a ')

    expect(apiRequest).not.toHaveBeenCalled()
  })

  it.each(TEST_LOCALES)('writes the %s empty result when nothing matches', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue([])
    const wrapper = mountCard({}, locale)

    await searchFor(wrapper, 'nobody')

    expect(wrapper.get('[data-testid="user-search-empty"]').text()).toBe(messageOf(locale, 'admin.userSupport.noResult'))
  })

  it('opens the sheet of the chosen hit', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([hitOf()]).mockResolvedValueOnce(sheetOf())
    const wrapper = mountCard()
    await searchFor(wrapper, 'mira')

    await wrapper.get('[data-testid="user-search-hit"]').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(`/admin/users/${USER_ID}`)
    expect(wrapper.findAll('[data-testid="user-sheet-character"]')).toHaveLength(2)
  })

  it('opens the sheet of the selected user passed by the page', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard()

    await wrapper.setProps({ selectedUserId: USER_ID })
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(`/admin/users/${USER_ID}`)
    expect(wrapper.find('[data-testid="user-sheet"]').exists()).toBe(true)
  })

  it.each(TEST_LOCALES)('shows each character with its %s token state, main flag and missing scopes', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard({ selectedUserId: USER_ID }, locale)
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="user-sheet-character"]')
    expect(rows[0].text()).toContain('Mira Tolland')
    expect(rows[0].text()).toContain(messageOf(locale, 'admin.userSupport.main'))
    expect(rows[0].text()).toContain(messageOf(locale, 'admin.userSupport.tokenState.valid'))
    expect(rows[1].text()).toContain(messageOf(locale, 'admin.userSupport.tokenState.invalid'))
    expect(rows[1].text()).toContain('esi-assets.read_assets.v1')
    expect(rows[1].text()).not.toContain(messageOf(locale, 'admin.userSupport.main'))
  })

  it.each(TEST_LOCALES)('writes the %s "never" text when the user never logged in', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf({ lastLoginAt: undefined }))
    const wrapper = mountCard({ selectedUserId: USER_ID }, locale)
    await flushPromises()

    expect(wrapper.get('[data-testid="user-sheet-last-login"]').text()).toContain(messageOf(locale, 'admin.userSupport.never'))
  })

  it('offers a trigger button only for the syncs a user message exists for', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard({ selectedUserId: USER_ID })
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="user-sheet-sync"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('[data-testid="user-sync-trigger"]').exists()).toBe(true)
    expect(rows[1].find('[data-testid="user-sync-trigger"]').exists()).toBe(false)
  })

  it.each(TEST_LOCALES)('writes the %s unknown text when a sync never wrote any data', async (locale) => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard({ selectedUserId: USER_ID }, locale)
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="user-sheet-sync"]')
    expect(rows[1].text()).toContain(messageOf(locale, 'admin.userSupport.unknown'))
    expect(rows[1].text()).not.toContain('NaN')
  })

  it('posts the sync type for the user then reloads the sheet', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard({ selectedUserId: USER_ID })
    await flushPromises()
    vi.mocked(apiRequest).mockClear()
    vi.mocked(apiRequest).mockResolvedValueOnce({ success: true, message: 'Sync triggered: industry' }).mockResolvedValueOnce(sheetOf())

    await wrapper.get('[data-testid="user-sync-trigger"]').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenNthCalledWith(1, `/admin/users/${USER_ID}/sync`, {
      method: 'POST',
      body: JSON.stringify({ type: 'industry' }),
    })
    expect(apiRequest).toHaveBeenNthCalledWith(2, `/admin/users/${USER_ID}`)
  })

  it('reloads the sheet on refresh', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sheetOf())
    const wrapper = mountCard({ selectedUserId: USER_ID })
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    await wrapper.get('[data-testid="user-sheet-refresh"]').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(`/admin/users/${USER_ID}`)
  })

  it.each(TEST_LOCALES)('writes the %s error when the sheet cannot be loaded', async (locale) => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Not Found'))
    const wrapper = mountCard({ selectedUserId: USER_ID }, locale)
    await flushPromises()

    expect(wrapper.get('[data-testid="user-sheet-error"]').text()).toBe(messageOf(locale, 'admin.userSupport.loadError'))
    expect(wrapper.find('[data-testid="user-sheet"]').exists()).toBe(false)
  })
})
