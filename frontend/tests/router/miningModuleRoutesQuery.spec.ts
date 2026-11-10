import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { MODULE_LAST_PAGE_STORAGE_KEY } from '@/composables/useModuleLastPage'

const OPERATION_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const SITE_ID = '6c1d90a4-7e52-4f3b-8d17-2a9e4b6f0c53'
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

describe('mining module routes, query and memory', () => {
  it.each([
    ['an operation detail', `/mining-operations/${OPERATION_ID}?x=1`, `/mining/operations/${OPERATION_ID}?x=1`],
    ['an operation invitation', '/mining-operations/join/AB12CD?x=1', '/mining/operations/join/AB12CD?x=1'],
    ['a site detail', `/mining-sites/${SITE_ID}?x=1`, `/mining/sites/${SITE_ID}?x=1`],
    ['the list without tab', '/mining-operations', '/mining/operations'],
    ['the list on its mine tab', '/mining-operations?tab=mine', '/mining/operations?tab=mine'],
  ])('keeps the query when it redirects the former link to %s', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it.each([
    ['another tab of the ledger', '/ledger?tab=pve', '/pve/revenue'],
    ['the ledger without tab', '/ledger', '/balance'],
  ])('moves %s to its page outside the mining module', async (_case, url, expectedPath) => {
    expect(await landingPathOf(url)).toBe(expectedPath)
  })

  it('sends an unknown view of the ledger mining tab to the personal mining page', async () => {
    expect(await landingPathOf('/ledger?tab=mining&view=unknown')).toBe('/mining/my-mining')
  })

  it('remembers the list page of the module when an operation detail is opened', async () => {
    await router.push(`/mining/operations/${OPERATION_ID}`)

    expect(JSON.parse(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY) ?? 'null')).toEqual({ mining: '/mining/operations' })
  })

  it('does not remember a page outside the module', async () => {
    await router.push('/ledger')

    expect(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY)).toBeNull()
  })
})
