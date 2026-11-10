import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

const OPERATION_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const SITE_ID = '6c1d90a4-7e52-4f3b-8d17-2a9e4b6f0c53'
const JOIN_CODE = 'AB12CD'
const MOON_CHUNK_ID = 'abc'
const NEUTRAL_PATH = '/dashboard'

beforeEach(async () => {
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

describe('mining module routes', () => {
  it.each([
    ['E1 the sites tab', '/mining-operations?tab=sites', '/mining/sites'],
    ['E2 the season tab', '/mining-operations?tab=season', '/mining/my-mining?tab=season'],
    ['E3 a moon chunk to plan', `/mining-operations?chunk=${MOON_CHUNK_ID}`, `/mining/moons?chunk=${MOON_CHUNK_ID}`],
    ['the available tab of the list', '/mining-operations?tab=available', '/mining/operations?tab=available'],
    ['the archive tab of the list', '/mining-operations?tab=archive', '/mining/operations?tab=archive'],
    ['E6 an operation detail', `/mining-operations/${OPERATION_ID}`, `/mining/operations/${OPERATION_ID}`],
    ['an operation invitation', `/mining-operations/join/${JOIN_CODE}`, `/mining/operations/join/${JOIN_CODE}`],
    ['a site detail', `/mining-sites/${SITE_ID}`, `/mining/sites/${SITE_ID}`],
  ])('redirects the former link to %s onto the mining module', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it.each([
    ['E5 the ledger mining tab', '/ledger?tab=mining', '/mining/my-mining'],
    ['the entries view of the ledger mining tab', '/ledger?tab=mining&view=entries', '/mining/my-mining'],
    ['the ore value view of the ledger mining tab', '/ledger?tab=mining&view=oreValue', '/mining/ore-value'],
    ['the moon chunks view of the ledger mining tab', '/ledger?tab=mining&view=moonChunks', '/mining/moons'],
    ['E4 the moon tax view of the ledger mining tab', '/ledger?tab=mining&view=moonTax', '/mining/moons?tab=tax'],
  ])('redirects the former link to %s onto the mining module', async (_case, oldUrl, expectedPath) => {
    expect(await landingPathOf(oldUrl)).toBe(expectedPath)
  })

  it('opens the operations page from the bare mining path', async () => {
    expect(await landingPathOf('/mining')).toBe('/mining/operations')
  })
})
