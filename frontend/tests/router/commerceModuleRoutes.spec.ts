import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

const SHARED_TOKEN = 'tok'
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

async function landingPathOf(oldUrl: string): Promise<string> {
  await router.push(oldUrl)
  return router.currentRoute.value.fullPath
}

describe('commerce module routes', () => {
  it('names the contracts page route and requires authentication', () => {
    const contractsRoute = router.getRoutes().find(candidate => candidate.name === 'contracts')

    expect({ path: contractsRoute?.path, requiresAuth: contractsRoute?.meta.requiresAuth, redirect: contractsRoute?.redirect }).toEqual({
      path: '/contracts',
      requiresAuth: true,
      redirect: undefined,
    })
  })

  it.each([
    ['the contracts tab of the inventory', '/assets?tab=contracts', '/contracts'],
    ['the contracts tab of the inventory with another parameter', '/assets?tab=contracts&foo=1', '/contracts?foo=1'],
    ['the shopping list page', '/shopping-list?x=1', '/appraisal?x=1'],
    ['a shared shopping list', `/shopping-list/shared/${SHARED_TOKEN}`, `/appraisal/shared/${SHARED_TOKEN}`],
  ])('redirects the former link to %s', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it('names the shared appraisal route after a shared shopping list redirection', async () => {
    await router.push(`/shopping-list/shared/${SHARED_TOKEN}`)

    expect({ name: router.currentRoute.value.name, token: router.currentRoute.value.params.token }).toEqual({
      name: 'shared-appraisal',
      token: SHARED_TOKEN,
    })
  })

  it('leaves another tab of the inventory on the inventory', async () => {
    expect(await landingPathOf('/assets?tab=other')).toBe('/assets?tab=other')
  })

  it('leaves the inventory without tab on the inventory', async () => {
    expect(await landingPathOf('/assets')).toBe('/assets')
  })

  it('keeps no route named shopping-list', () => {
    expect(router.getRoutes().filter(candidate => candidate.name === 'shopping-list')).toHaveLength(0)
  })
})
