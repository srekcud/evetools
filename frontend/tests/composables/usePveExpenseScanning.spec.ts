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

const PROJECT_LIST_UNAVAILABLE = {
  corporationName: 'Fictional Mining Corp',
  projectName: null,
  characterName: null,
  reason: 'esi_error',
  httpStatus: 503,
}

const SECOND_CORPORATION_WITHOUT_VALID_CHARACTER = {
  corporationName: 'Second Fictional Corp',
  projectName: null,
  characterName: null,
  reason: 'no_valid_character',
  httpStatus: null,
}

function lootSalesScan(overrides: Record<string, unknown>): Record<string, unknown> {
  return {
    scannedTransactions: 5,
    scannedContracts: 1,
    scannedProjects: 1,
    detectedSales: [],
    noLootTypesConfigured: false,
    charactersToReconnect: [],
    corporationProjectErrors: [],
    ...overrides,
  }
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

type ScanningCallbacks = {
  onError?: (message: string) => void
  onOpenLootConfig?: () => void
}

function mountScanning(callbacks: ScanningCallbacks = {}): ReturnType<typeof usePveExpenseScanning> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  let scanning!: ReturnType<typeof usePveExpenseScanning>
  const app = createApp({
    setup: () => {
      scanning = usePveExpenseScanning(async () => {}, callbacks.onError ?? (() => {}), callbacks.onOpenLootConfig ?? (() => {}))
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
        corporationProjectErrors: [SECOND_CORPORATION_WITHOUT_VALID_CHARACTER],
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
    expect(result?.corporationProjectErrors).toEqual([SECOND_CORPORATION_WITHOUT_VALID_CHARACTER])
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
        corporationProjectErrors: [],
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

  it('opens no dialog and reports the HTTP status when the loot sale scan fails', async () => {
    const onError = vi.fn()
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse({ detail: 'Internal error' }, 500),
      () => jsonResponse({ scannedContracts: 6, detectedContracts: [] }),
    )
    const scanning = mountScanning({ onError, onOpenLootConfig })

    await scanning.scanLootSales(7)

    expect(scanning.showLootScanResults.value).toBe(false)
    expect(scanning.lootScanResults.value).toBeNull()
    expect(onError.mock.calls).toEqual([['Loot sales and corporation projects scan failed (HTTP 500)']])
    expect(onOpenLootConfig).not.toHaveBeenCalled()
  })

  it('opens no dialog and reports the HTTP status when the loot contract scan fails', async () => {
    const onError = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({ detectedSales: [CORP_PROJECT_SALE] })),
      () => jsonResponse({ detail: 'Service unavailable' }, 503),
    )
    const scanning = mountScanning({ onError })

    await scanning.scanLootSales(30)

    expect(scanning.showLootScanResults.value).toBe(false)
    expect(scanning.lootScanResults.value).toBeNull()
    expect(onError.mock.calls).toEqual([['Loot contracts scan failed (HTTP 503)']])
  })

  it('reports the loot sale scan failure when both scans fail', async () => {
    const onError = vi.fn()
    respondWith(
      () => jsonResponse({ detail: 'Bad gateway' }, 502),
      () => jsonResponse({ detail: 'Gateway timeout' }, 504),
    )
    const scanning = mountScanning({ onError })

    await scanning.scanLootSales(14)

    expect(scanning.showLootScanResults.value).toBe(false)
    expect(onError.mock.calls).toEqual([['Loot sales and corporation projects scan failed (HTTP 502)']])
  })

  it('opens the loot type configuration instead of the dialog when nothing was found without loot types', async () => {
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({ noLootTypesConfigured: true, scannedTransactions: null, scannedContracts: null, scannedProjects: 0 })),
      () => jsonResponse({ scannedContracts: 0, detectedContracts: [] }),
    )
    const scanning = mountScanning({ onOpenLootConfig })

    await scanning.scanLootSales(30)

    expect(onOpenLootConfig).toHaveBeenCalledTimes(1)
    expect(scanning.showLootScanResults.value).toBe(false)
  })

  it('opens the dialog with the project error instead of the loot type configuration when the projects could not be read', async () => {
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({
        noLootTypesConfigured: true,
        scannedTransactions: null,
        scannedContracts: null,
        scannedProjects: null,
        corporationProjectErrors: [PROJECT_LIST_UNAVAILABLE],
      })),
      () => jsonResponse({ scannedContracts: 0, detectedContracts: [] }),
    )
    const scanning = mountScanning({ onOpenLootConfig })

    await scanning.scanLootSales(30)

    expect(onOpenLootConfig).not.toHaveBeenCalled()
    expect(scanning.showLootScanResults.value).toBe(true)
    const result = scanning.lootScanResults.value
    expect(result?.noLootTypesConfigured).toBe(true)
    expect(result?.scannedProjects).toBeNull()
    expect(result?.corporationProjectErrors).toEqual([PROJECT_LIST_UNAVAILABLE])
  })

  it('opens the dialog instead of the loot type configuration when a character has to be reconnected', async () => {
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({
        noLootTypesConfigured: true,
        scannedTransactions: null,
        scannedContracts: null,
        scannedProjects: 0,
        charactersToReconnect: ['Pilot Four'],
      })),
      () => jsonResponse({ scannedContracts: 0, detectedContracts: [] }),
    )
    const scanning = mountScanning({ onOpenLootConfig })

    await scanning.scanLootSales(30)

    expect(onOpenLootConfig).not.toHaveBeenCalled()
    expect(scanning.showLootScanResults.value).toBe(true)
    expect(scanning.lootScanResults.value?.charactersToReconnect).toEqual(['Pilot Four'])
  })

  it('keeps the transactions unknown and counts only the loot contract scan when no loot type is configured', async () => {
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({
        noLootTypesConfigured: true,
        scannedTransactions: null,
        scannedContracts: null,
        scannedProjects: 1,
        detectedSales: [CORP_PROJECT_SALE],
      })),
      () => jsonResponse({ scannedContracts: 2, detectedContracts: [] }),
    )
    const scanning = mountScanning({ onOpenLootConfig })

    await scanning.scanLootSales(30)

    expect(onOpenLootConfig).not.toHaveBeenCalled()
    expect(scanning.showLootScanResults.value).toBe(true)
    const result = scanning.lootScanResults.value
    expect(result?.scannedTransactions).toBeNull()
    expect(result?.scannedContracts).toBe(2)
    expect(result?.scannedProjects).toBe(1)
    expect(result?.noLootTypesConfigured).toBe(true)
    expect(result?.detectedSales.map(sale => [sale.projectName, sale.quantity, sale.price])).toEqual([['Tritanium Drive', 1000, 12500]])
  })

  it('asks the value of a contribution whose reward is unknown but not of a contribution of known value', async () => {
    respondWith(
      () => jsonResponse(lootSalesScan({
        scannedProjects: 2,
        detectedSales: [
          { ...CORP_PROJECT_SALE, transactionId: -604311829, characterName: 'Pilot One', price: null },
          CORP_PROJECT_SALE,
        ],
      })),
      () => jsonResponse({ scannedContracts: 0, detectedContracts: [] }),
    )
    const scanning = mountScanning()

    await scanning.scanLootSales(30)

    expect(scanning.lootScanResults.value?.detectedSales.map(sale => [sale.characterName, sale.price, sale.needsPriceInput])).toEqual([
      ['Pilot One', null, true],
      ['Pilot Three', 12500, false],
    ])
  })

  it('asks the value of a contribution whose reward is an explicit zero', async () => {
    respondWith(
      () => jsonResponse(lootSalesScan({
        detectedSales: [{ ...CORP_PROJECT_SALE, transactionId: -604311829, characterName: 'Pilot One', typeName: '310x Tritanium', quantity: 310, price: 0 }],
      })),
      () => jsonResponse({ scannedContracts: 0, detectedContracts: [] }),
    )
    const scanning = mountScanning()

    await scanning.scanLootSales(30)

    expect(scanning.lootScanResults.value?.detectedSales.map(sale => [sale.characterName, sale.quantity, sale.price, sale.needsPriceInput])).toEqual([
      ['Pilot One', 310, 0, true],
    ])
  })

  it('opens the dialog instead of the loot type configuration when only a loot contract was detected without loot types', async () => {
    const onOpenLootConfig = vi.fn()
    respondWith(
      () => jsonResponse(lootSalesScan({ noLootTypesConfigured: true, scannedTransactions: null, scannedContracts: null, scannedProjects: 0 })),
      () => jsonResponse({
        scannedContracts: 3,
        detectedContracts: [{
          contractId: 214748901,
          description: '12x Rogue Drone Infestation Data',
          items: [{ typeId: 60459, typeName: 'Rogue Drone Infestation Data', quantity: 12 }],
          totalQuantity: 12,
          contractPrice: 4560000,
          suggestedPrice: 4400000,
          date: '2026-10-25',
          characterName: 'Pilot Two',
        }],
      }),
    )
    const scanning = mountScanning({ onOpenLootConfig })

    await scanning.scanLootSales(30)

    expect(onOpenLootConfig).not.toHaveBeenCalled()
    expect(scanning.showLootScanResults.value).toBe(true)
    expect(scanning.lootScanResults.value?.scannedContracts).toBe(3)
    expect(scanning.lootScanResults.value?.detectedSales.map(sale => [sale.source, sale.contractId, sale.quantity, sale.price])).toEqual([
      ['contract', 214748901, 12, 4560000],
    ])
  })
})

const PILOT_ONE_CONTRIBUTION_DELTA = {
  ...CORP_PROJECT_SALE,
  transactionId: -1497420833,
  characterId: 2112000001,
  characterName: 'Pilot One',
  typeName: '600x Tritanium',
  quantity: 600,
  price: 7500,
  accountedQuantity: 1000,
}

const PILOT_TWO_FIRST_CONTRIBUTION = {
  ...CORP_PROJECT_SALE,
  transactionId: -305718244,
  characterId: 2112000002,
  characterName: 'Pilot Two',
  typeName: '400x Tritanium',
  quantity: 400,
  price: 5000,
  accountedQuantity: 0,
}

const MARKET_LOOT_SALE = {
  transactionId: 8200017,
  contractId: 0,
  type: 'loot_sale',
  typeId: 60459,
  typeName: '12x Rogue Drone Infestation Data',
  quantity: 12,
  price: 4560000,
  dateIssued: '2026-10-25',
  characterName: 'Pilot Two',
  source: 'market',
}

function respondToScanAndImport(detectedSales: unknown[], importResult: Record<string, number>): unknown[] {
  const importBodies: unknown[] = []
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.startsWith('/api/pve/scan-loot-sales')) return jsonResponse(lootSalesScan({ detectedSales }))
    if (url.startsWith('/api/pve/scan-loot-contracts')) return jsonResponse({ scannedContracts: 0, detectedContracts: [] })
    if (url === '/api/pve/import-loot-sales') {
      importBodies.push(JSON.parse(String(init?.body)))
      return jsonResponse(importResult)
    }
    throw new Error(`Unexpected request ${url}`)
  })
  return importBodies
}

function detectedSaleSummaries(scanning: ReturnType<typeof usePveExpenseScanning>): [string, number][] {
  return (scanning.lootScanResults.value?.detectedSales ?? []).map(sale => [sale.characterName, sale.quantity])
}

describe('usePveExpenseScanning pending corporation project contribution', () => {
  it('sends the project, the character, the delta and the accounted quantity when importing a contribution', async () => {
    const importBodies = respondToScanAndImport([PILOT_ONE_CONTRIBUTION_DELTA], { imported: 1, rejectedZeroPrice: 0, rejectedContributions: 0 })
    const onError = vi.fn()
    const scanning = mountScanning({ onError })
    await scanning.scanLootSales(30)

    await scanning.importSingleLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(importBodies).toEqual([{
      sales: [{
        type: 'corp_project',
        typeId: 34,
        typeName: 'Tritanium Drive: 600x Tritanium',
        quantity: 600,
        price: 7500,
        dateIssued: '2026-10-27',
        transactionId: -1497420833,
        projectId: 'a1b2c3d4-project',
        characterId: 2112000001,
        accountedQuantity: 1000,
      }],
      declined: [],
    }])
    expect(onError).not.toHaveBeenCalled()
    expect(detectedSaleSummaries(scanning)).toEqual([])
  })

  it('sends an explicit zero accounted quantity for a first contribution', async () => {
    const importBodies = respondToScanAndImport([PILOT_TWO_FIRST_CONTRIBUTION], { imported: 1, rejectedZeroPrice: 0, rejectedContributions: 0 })
    const scanning = mountScanning()
    await scanning.scanLootSales(30)

    await scanning.importSingleLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(importBodies).toEqual([{
      sales: [{
        type: 'corp_project',
        typeId: 34,
        typeName: 'Tritanium Drive: 400x Tritanium',
        quantity: 400,
        price: 5000,
        dateIssued: '2026-10-27',
        transactionId: -305718244,
        projectId: 'a1b2c3d4-project',
        characterId: 2112000002,
        accountedQuantity: 0,
      }],
      declined: [],
    }])
  })

  it('reports a contribution counted in the meantime and drops it from the list', async () => {
    respondToScanAndImport([PILOT_ONE_CONTRIBUTION_DELTA, PILOT_TWO_FIRST_CONTRIBUTION], { imported: 0, rejectedZeroPrice: 0, rejectedContributions: 1 })
    const onError = vi.fn()
    const scanning = mountScanning({ onError })
    await scanning.scanLootSales(30)

    await scanning.importSingleLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(onError.mock.calls).toEqual([['Contribution already counted in the meantime (sync or another tab): run the scan again.']])
    expect(detectedSaleSummaries(scanning)).toEqual([['Pilot Two', 400]])
  })

  it('sends the project, the character, the delta and the accounted quantity when declining a contribution', async () => {
    const importBodies = respondToScanAndImport([PILOT_ONE_CONTRIBUTION_DELTA], { imported: 0, rejectedZeroPrice: 0, rejectedContributions: 0 })
    const onError = vi.fn()
    const scanning = mountScanning({ onError })
    await scanning.scanLootSales(30)

    await scanning.ignoreLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(importBodies).toEqual([{
      sales: [],
      declined: [{
        type: 'corp_project',
        transactionId: -1497420833,
        projectId: 'a1b2c3d4-project',
        characterId: 2112000001,
        quantity: 600,
        accountedQuantity: 1000,
      }],
    }])
    expect(onError).not.toHaveBeenCalled()
    expect(detectedSaleSummaries(scanning)).toEqual([])
  })

  it('reports a declined contribution counted in the meantime and drops it from the list', async () => {
    respondToScanAndImport([PILOT_ONE_CONTRIBUTION_DELTA, PILOT_TWO_FIRST_CONTRIBUTION], { imported: 0, rejectedZeroPrice: 0, rejectedContributions: 1 })
    const onError = vi.fn()
    const scanning = mountScanning({ onError })
    await scanning.scanLootSales(30)

    await scanning.ignoreLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(onError.mock.calls).toEqual([['Contribution already counted in the meantime (sync or another tab): run the scan again.']])
    expect(detectedSaleSummaries(scanning)).toEqual([['Pilot Two', 400]])
  })

  it('keeps declining a market sale by its transaction identifier only', async () => {
    const importBodies = respondToScanAndImport([MARKET_LOOT_SALE], { imported: 0, rejectedZeroPrice: 0, rejectedContributions: 0 })
    const scanning = mountScanning()
    await scanning.scanLootSales(30)

    await scanning.ignoreLootSale(scanning.lootScanResults.value!.detectedSales[0]!)

    expect(importBodies).toEqual([{ sales: [], declined: [{ transactionId: 8200017 }] }])
  })
})
