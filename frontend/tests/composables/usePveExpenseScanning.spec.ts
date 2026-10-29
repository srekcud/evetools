import { createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { usePveExpenseScanning } from '@/composables/usePveExpenseScanning'
import { authFetch } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  authFetch: vi.fn(),
}))

const CORP_PROJECT_SALE = {
  transactionId: -1830477215,
  contractId: 0,
  projectId: 'a1b2c3d4-project',
  type: 'corp_project',
  typeId: 34,
  typeName: '1000x Tritanium',
  quantity: 1000,
  price: 12500,
  dateIssued: '2026-10-27',
  characterName: 'Pilot Three',
  source: 'corp_project',
  projectName: 'Tritanium Drive',
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function respondWith(lootSales: () => Response, lootContracts: () => Response) {
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.startsWith('/api/pve/scan-loot-sales')) return lootSales()
    if (url.startsWith('/api/pve/scan-loot-contracts')) return lootContracts()
    throw new Error(`Unexpected request ${url}`)
  })
}

function mountScanning(): ReturnType<typeof usePveExpenseScanning> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  let scanning!: ReturnType<typeof usePveExpenseScanning>
  const app = createApp({
    setup: () => {
      scanning = usePveExpenseScanning(async () => {}, () => {}, () => {})
      return () => null
    },
  })
  app.use(pinia)
  app.use(i18n)
  app.mount(document.createElement('div'))
  return scanning
}

afterEach(() => {
  vi.mocked(authFetch).mockReset()
})

describe('usePveExpenseScanning loot sale scan result', () => {
  it('passes an unknown scannedProjects and both lists through unchanged', async () => {
    respondWith(
      () => jsonResponse({
        scannedTransactions: 7,
        scannedContracts: 1,
        scannedProjects: null,
        detectedSales: [CORP_PROJECT_SALE],
        noLootTypesConfigured: false,
        charactersToReconnect: ['Pilot One', 'Pilot Two', 'Pilot Four'],
        corporationsWithoutValidCharacter: ['Second Fictional Corp'],
      }),
      () => jsonResponse({ scannedContracts: 2, detectedContracts: [] }),
    )
    const scanning = mountScanning()

    await scanning.scanLootSales(30)

    const result = scanning.lootScanResults.value
    expect(result?.scannedTransactions).toBe(7)
    expect(result?.scannedContracts).toBe(3)
    expect(result?.scannedProjects).toBeNull()
    expect(result?.charactersToReconnect).toEqual(['Pilot One', 'Pilot Two', 'Pilot Four'])
    expect(result?.corporationsWithoutValidCharacter).toEqual(['Second Fictional Corp'])
    expect(result?.detectedSales.map(sale => [sale.characterName, sale.quantity, sale.price])).toEqual([['Pilot Three', 1000, 12500]])
  })

  it('keeps a scannedProjects of 0 for a corporation read without any project', async () => {
    respondWith(
      () => jsonResponse({
        scannedTransactions: 4,
        scannedContracts: 0,
        scannedProjects: 0,
        detectedSales: [],
        noLootTypesConfigured: false,
        charactersToReconnect: [],
        corporationsWithoutValidCharacter: [],
      }),
      () => jsonResponse({ scannedContracts: 1, detectedContracts: [] }),
    )
    const scanning = mountScanning()

    await scanning.scanLootSales(14)

    const result = scanning.lootScanResults.value
    expect(result?.scannedTransactions).toBe(4)
    expect(result?.scannedContracts).toBe(1)
    expect(result?.scannedProjects).toBe(0)
  })

  it('falls back to unknown scannedProjects and empty lists when the loot sale scan fails', async () => {
    respondWith(
      () => jsonResponse({ detail: 'Internal error' }, 500),
      () => jsonResponse({ scannedContracts: 6, detectedContracts: [] }),
    )
    const scanning = mountScanning()

    await scanning.scanLootSales(7)

    const result = scanning.lootScanResults.value
    expect(result?.scannedContracts).toBe(6)
    expect(result?.scannedProjects).toBeNull()
    expect(result?.charactersToReconnect).toEqual([])
    expect(result?.corporationsWithoutValidCharacter).toEqual([])
  })
})
