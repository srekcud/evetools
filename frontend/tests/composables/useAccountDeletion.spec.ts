import { createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { useAccountDeletion } from '@/composables/useAccountDeletion'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'

const calls: string[] = []
const routerPush = vi.fn((path: string) => {
  calls.push(`router.push ${path}`)
  return Promise.resolve()
})
const showToast = vi.fn((message: string, type: string) => {
  calls.push(`toast ${type} ${message}`)
})
const unsubscribeBrowser = vi.fn(() => {
  calls.push('push.unsubscribeBrowser')
  return Promise.resolve()
})

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

vi.mock('vue-router', async importOriginal => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRouter: () => ({ push: routerPush }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ showToast }),
}))

vi.mock('@/composables/usePushNotifications', () => ({
  usePushNotifications: () => ({ unsubscribeBrowser }),
}))

const labels = en.characters.deleteAccount
const MAIN_CHARACTER_NAME = 'Kaelen Vorn'

function mountAccountDeletion(): ReturnType<typeof useAccountDeletion> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  let accountDeletion!: ReturnType<typeof useAccountDeletion>
  const app = createApp({
    setup: () => {
      accountDeletion = useAccountDeletion()
      return () => null
    },
  })
  app.use(pinia)
  app.use(i18n)
  app.mount(document.createElement('div'))
  return accountDeletion
}

function signIn(): void {
  const authStore = useAuthStore()
  authStore.setToken('jwt-of-the-deleted-account')
}

function spyOnSessionTeardown(): void {
  const syncStore = useSyncStore()
  const authStore = useAuthStore()
  vi.spyOn(syncStore, 'disconnect').mockImplementation(() => {
    calls.push('sync.disconnect')
  })
  const realLogout = authStore.logout
  vi.spyOn(authStore, 'logout').mockImplementation(() => {
    calls.push('auth.logout')
    realLogout()
  })
}

beforeEach(() => {
  calls.length = 0
  vi.clearAllMocks()
  localStorage.clear()
})

describe('useAccountDeletion', () => {
  it('sends the typed main character name as the confirmation of the deletion', async () => {
    const { deleteAccount } = mountAccountDeletion()
    signIn()
    spyOnSessionTeardown()
    vi.mocked(apiRequest).mockResolvedValue(null)

    await deleteAccount(MAIN_CHARACTER_NAME)

    expect(apiRequest).toHaveBeenCalledTimes(1)
    expect(apiRequest).toHaveBeenCalledWith('/me', {
      method: 'DELETE',
      body: JSON.stringify({ confirmation: 'Kaelen Vorn' }),
    })
  })

  it('deletes the account then unsubscribes push, closes Mercure, logs out locally and redirects to login with a toast', async () => {
    const { deleteAccount, isDeleting } = mountAccountDeletion()
    signIn()
    spyOnSessionTeardown()
    vi.mocked(apiRequest).mockImplementation(async (endpoint, options) => {
      calls.push(`${options?.method} ${endpoint}`)
      return null
    })

    const deleted = await deleteAccount(MAIN_CHARACTER_NAME)

    expect(deleted).toBe(true)
    expect(calls).toEqual([
      'DELETE /me',
      'push.unsubscribeBrowser',
      'sync.disconnect',
      'auth.logout',
      'router.push /login',
      `toast success ${labels.succeeded}`,
    ])
    expect(apiRequest).toHaveBeenCalledTimes(1)
    expect(localStorage.getItem('jwt_token')).toBeNull()
    expect(useAuthStore().token).toBeNull()
    expect(isDeleting.value).toBe(false)
  })

  it('keeps the user logged in and shows an error toast when the deletion fails', async () => {
    const { deleteAccount, isDeleting } = mountAccountDeletion()
    signIn()
    spyOnSessionTeardown()
    vi.mocked(apiRequest).mockRejectedValue(new Error('API error: 500'))
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const deleted = await deleteAccount(MAIN_CHARACTER_NAME)

    expect(deleted).toBe(false)
    expect(calls).toEqual([`toast error ${labels.failed}`])
    expect(useAuthStore().token).toBe('jwt-of-the-deleted-account')
    expect(localStorage.getItem('jwt_token')).toBe('jwt-of-the-deleted-account')
    expect(routerPush).not.toHaveBeenCalled()
    expect(isDeleting.value).toBe(false)
  })
})
