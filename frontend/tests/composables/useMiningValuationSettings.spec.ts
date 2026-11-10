import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'
import { apiRequest } from '@/services/api'
import { useMiningValuationProfileStore } from '@/stores/miningValuationProfile'
import {
  BROWSER_STRUCTURE_ID,
  BROWSER_STRUCTURE_NAME,
  LEGACY_EXPORT_TAX_KEY,
  LEGACY_STRUCTURE_KEY,
  PERIMETER_STRUCTURE_ID,
  PERIMETER_STRUCTURE_NAME,
  miningValuationProfile,
  valuationProfileServer,
} from '../components/ledger/miningValuationProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SERVER_EXPORT_TAX = 750
const NEW_EXPORT_TAX = 1_250

type StorageSpy = { mock: { calls: unknown[][] } }

function legacyKeyCalls(spy: StorageSpy): unknown[][] {
  return spy.mock.calls.filter(([key]) => key === LEGACY_STRUCTURE_KEY || key === LEGACY_EXPORT_TAX_KEY)
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('useMiningValuationSettings', () => {
  it('exposes the profile of the store and writes the structure and the export tax back to it, never to the browser', async () => {
    const serverProfile = miningValuationProfile({
      market: 'structure',
      side: 'sell',
      form: 'raw',
      structureId: PERIMETER_STRUCTURE_ID,
      structureName: PERIMETER_STRUCTURE_NAME,
      exportTax: SERVER_EXPORT_TAX,
    })
    const server = valuationProfileServer(serverProfile)
    vi.mocked(apiRequest).mockImplementation(server.respondingWith(() => new Promise(() => {})))
    localStorage.setItem(LEGACY_STRUCTURE_KEY, JSON.stringify({ id: 1, name: 'Browser structure' }))
    localStorage.setItem(LEGACY_EXPORT_TAX_KEY, '1200')
    useMiningValuationProfileStore().profile = serverProfile
    const getItem = vi.spyOn(Storage.prototype, 'getItem')
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    const removeItem = vi.spyOn(Storage.prototype, 'removeItem')

    const settings = useMiningValuationSettings()
    const exposed = [settings.market.value, settings.side.value, settings.form.value, settings.selectedStructure.value, settings.selectedStructureId.value, settings.exportTax.value]
    settings.selectStructure({ id: BROWSER_STRUCTURE_ID, name: BROWSER_STRUCTURE_NAME })
    await flushPromises()
    settings.setExportTax(NEW_EXPORT_TAX)
    await flushPromises()

    expect(exposed).toEqual(['structure', 'sell', 'raw', { id: PERIMETER_STRUCTURE_ID, name: PERIMETER_STRUCTURE_NAME }, PERIMETER_STRUCTURE_ID, SERVER_EXPORT_TAX])
    expect(server.state.patches).toEqual([
      { structureId: BROWSER_STRUCTURE_ID, structureName: BROWSER_STRUCTURE_NAME },
      { exportTax: NEW_EXPORT_TAX },
    ])
    expect([settings.selectedStructure.value, settings.exportTax.value]).toEqual([{ id: BROWSER_STRUCTURE_ID, name: BROWSER_STRUCTURE_NAME }, NEW_EXPORT_TAX])
    expect([legacyKeyCalls(getItem), legacyKeyCalls(setItem), legacyKeyCalls(removeItem)]).toEqual([[], [], []])
  })

  it('clears the structure of the profile with a single clearStructure patch and shows no structure afterwards', async () => {
    const serverProfile = miningValuationProfile({
      market: 'jita',
      structureId: PERIMETER_STRUCTURE_ID,
      structureName: PERIMETER_STRUCTURE_NAME,
    })
    const server = valuationProfileServer(serverProfile)
    vi.mocked(apiRequest).mockImplementation(server.respondingWith(() => new Promise(() => {})))
    useMiningValuationProfileStore().profile = serverProfile

    const settings = useMiningValuationSettings()
    settings.clearStructure()
    await flushPromises()

    expect(server.state.patches).toEqual([{ clearStructure: true }])
    expect([settings.selectedStructure.value, settings.selectedStructureId.value]).toEqual([{ id: null, name: '' }, null])
  })
})
