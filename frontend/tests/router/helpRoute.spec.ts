import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'

beforeAll(() => {
  vi.setConfig({ testTimeout: 30000 })
})

beforeEach(async () => {
  localStorage.clear()
  setActivePinia(createPinia())
  await router.push('/legal')
})

describe('help route', () => {
  it('opens /help named help for a visitor who is not signed in, without redirecting to login', async () => {
    const resolved = router.resolve('/help')

    await router.push('/help')

    const current = router.currentRoute.value
    expect({ resolvedName: resolved.name, path: current.fullPath, name: current.name, requiresAuth: current.meta.requiresAuth })
      .toEqual({ resolvedName: 'help', path: '/help', name: 'help', requiresAuth: false })
  })
})
