import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import WalletRefreshButton from '@/components/group-industry/WalletRefreshButton.vue'
import { apiRequest } from '@/services/api'
import { deferred } from '../../deferred'
import type { WalletReading } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-953'
const BUTTON = '[data-testid="wallet-refresh"]'
const NONE_TO_LAUNCH = '[data-testid="wallet-refresh-none"]'
const REFRESH_PATH = `/industry/projects/${PROJECT_ID}/wallet-refresh`

const AUDREY_NEXT_READ_AT = '2026-11-13T21:04:00+00:00'
const BRUNO_NEXT_READ_AT = '2026-11-13T21:37:00+00:00'

const READINGS: WalletReading[] = [
  { characterName: 'Audrey Vance', status: 'read', readAt: '2026-11-13T20:04:10+00:00', nextReadAt: AUDREY_NEXT_READ_AT },
  { characterName: 'Bruno Keld', status: 'read', readAt: '2026-11-13T20:37:00+00:00', nextReadAt: BRUNO_NEXT_READ_AT },
]

function clockTime(iso: string): string {
  const date = new Date(iso)
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function mountButton(): VueWrapper {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(WalletRefreshButton, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, readings: READINGS } })
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('WalletRefreshButton', () => {
  it('posts the refresh request of the project when clicked', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ queuedCharacters: 2, notDueCharacters: 0 })
    const wrapper = mountButton()

    await wrapper.get(BUTTON).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(REFRESH_PATH, { method: 'POST', body: JSON.stringify({}) })
    expect(wrapper.find(NONE_TO_LAUNCH).exists()).toBe(false)
  })

  it('stays disabled while the request is running', async () => {
    const pending = deferred<{ queuedCharacters: number; notDueCharacters: number }>()
    vi.mocked(apiRequest).mockReturnValueOnce(pending.promise)
    const wrapper = mountButton()

    await wrapper.get(BUTTON).trigger('click')
    expect(wrapper.get(BUTTON).attributes('disabled')).toBeDefined()

    pending.resolve({ queuedCharacters: 1, notDueCharacters: 1 })
    await flushPromises()
    expect(wrapper.get(BUTTON).attributes('disabled')).toBeUndefined()
  })

  it('says nothing was launched and gives the earliest next reading when no character is due', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ queuedCharacters: 0, notDueCharacters: 2 })
    const wrapper = mountButton()

    await wrapper.get(BUTTON).trigger('click')
    await flushPromises()

    expect(wrapper.get(NONE_TO_LAUNCH).text()).toContain(clockTime(AUDREY_NEXT_READ_AT))
  })
})
