import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import Settings from '@/views/Settings.vue'

const NEUTRAL_PATH = '/dashboard'

beforeAll(() => {
  vi.setConfig({ testTimeout: 30000 })
})

beforeEach(async () => {
  localStorage.clear()
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.token = 'pilot-token'
  authStore.user = { id: 7 } as never
  await router.push(NEUTRAL_PATH)
})

const SECTIONS = ['account', 'display', 'market', 'industry', 'mining', 'pve', 'notifications']

describe('settings routes', () => {
  it.each([
    ['without section', '/settings', '/settings/account'],
    ['with an unknown section', '/settings/foo?x=1', '/settings/account?x=1'],
  ])('redirects the settings page %s to the account section', async (_case, url, expectedPath) => {
    await router.push(url)

    expect(router.currentRoute.value.fullPath).toBe(expectedPath)
  })

  it('renders the settings view on each of the seven sections, named settings and behind authentication', async () => {
    const opened = []
    for (const section of SECTIONS) {
      await router.push(`/settings/${section}`)
      const current = router.currentRoute.value
      opened.push({ path: current.fullPath, name: current.name, section: current.params.section, requiresAuth: current.meta.requiresAuth, view: current.matched[0]?.components?.default })
    }

    expect(opened).toEqual(SECTIONS.map(section => ({ path: `/settings/${section}`, name: 'settings', section, requiresAuth: true, view: Settings })))
  })
})
