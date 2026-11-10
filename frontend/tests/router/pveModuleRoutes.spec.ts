import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

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

describe('pve module and balance routes', () => {
  it.each([
    ['the ledger without tab', '/ledger', '/balance'],
    ['the ledger dashboard tab', '/ledger?tab=dashboard', '/balance'],
    ['the ledger pve tab', '/ledger?tab=pve', '/pve/revenue'],
    ['the ledger escalations tab', '/ledger?tab=escalations', '/pve/escalations'],
    ['the ledger industry tab', '/ledger?tab=industry', '/balance?tab=industry'],
    ['the ledger settings tab', '/ledger?tab=settings', '/balance?tab=settings'],
    ['an unknown ledger tab', '/ledger?tab=unknown', '/balance'],
    ['the former escalations page', '/escalations', '/pve/escalations'],
    ['the former pve page', '/pve', '/pve/revenue'],
  ])('redirects the former link to %s', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it.each([
    ['the ledger without tab', '/ledger?x=1', '/balance?x=1'],
    ['the ledger dashboard tab', '/ledger?tab=dashboard&x=1', '/balance?x=1'],
    ['the ledger pve tab', '/ledger?tab=pve&x=1', '/pve/revenue?x=1'],
    ['the ledger escalations tab', '/ledger?tab=escalations&x=1', '/pve/escalations?x=1'],
    ['the ledger industry tab', '/ledger?tab=industry&x=1', '/balance?tab=industry&x=1'],
    ['the ledger settings tab', '/ledger?tab=settings&x=1', '/balance?tab=settings&x=1'],
    ['the former escalations page', '/escalations?x=1', '/pve/escalations?x=1'],
    ['the former pve page', '/pve?x=1', '/pve/revenue?x=1'],
  ])('keeps the other query parameters when it redirects the former link to %s', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it('names the three pages after the module and the balance', () => {
    const pathsByName = ['pve-revenue', 'pve-escalations', 'balance'].map(name => router.getRoutes().find(route => route.name === name)?.path)

    expect(pathsByName).toEqual(['/pve/revenue', '/pve/escalations', '/balance'])
  })
})
