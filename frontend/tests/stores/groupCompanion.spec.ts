import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import type { BomItem, ProjectPriceAlert } from '@/stores/group-industry/types'
import { bomLine, companionProject, COMPANION_PROJECT_ID } from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const BOM_PATH = `${PROJECT_PATH}/bom`
const COCKPIT_PATH = `${PROJECT_PATH}/cockpit`
const RESERVATION_PATH = `${BOM_PATH}/bom-nocxium/reservation`

const PRICE_ALERT: ProjectPriceAlert = {
  typeId: 11_993,
  typeName: 'Hammerhead II',
  quantity: 6,
  jitaUnitPrice: 690_000,
  breakEvenPerUnit: 760_000,
  gapPerUnit: 70_000,
}

let currentBom: BomItem[]
let currentPriceAlert: ProjectPriceAlert | null

function nocxium(overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({
    id: 'bom-nocxium',
    typeId: 38,
    typeName: 'Nocxium',
    requiredQuantity: 100,
    remainingQuantity: 100,
    freeQuantity: 80,
    reservedQuantity: 20,
    ...overrides,
  })
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  currentBom = [nocxium()]
  currentPriceAlert = null
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === PROJECT_PATH) return companionProject()
    if (endpoint === BOM_PATH) return currentBom
    if (endpoint === COCKPIT_PATH) return { priceAlert: currentPriceAlert }
    return []
  })
})

describe('useGroupCompanionStore', () => {
  it('reserves the whole free quantity of the line then reloads the BOM', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    await store.claim('bom-nocxium')

    expect({
      reserved: vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === RESERVATION_PATH),
      bomLoads: callsTo(BOM_PATH),
    }).toEqual({
      reserved: [[RESERVATION_PATH, { method: 'POST', body: JSON.stringify({ quantity: 80 }) }]],
      bomLoads: 2,
    })
  })

  it('reloads the BOM and the project on a line reserved event', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    currentBom = [nocxium({ freeQuantity: 50, reservedQuantity: 50 })]

    await store.handleEvent('line_reserved')

    expect({
      reservedQuantities: store.bomItems.map(item => item.reservedQuantity),
      bomLoads: callsTo(BOM_PATH),
      projectLoads: callsTo(PROJECT_PATH),
    }).toEqual({ reservedQuantities: [50], bomLoads: 2, projectLoads: 2 })
  })

  it('keeps the price alert of the cockpit when opened and refreshes it on every project event, dropping it once Jita is back above the break-even', async () => {
    currentPriceAlert = PRICE_ALERT
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const opened = store.priceAlert
    currentPriceAlert = null

    await store.handleEvent('sale_recorded')

    expect({ opened, refreshed: store.priceAlert, cockpitLoads: callsTo(COCKPIT_PATH) }).toEqual({
      opened: PRICE_ALERT,
      refreshed: null,
      cockpitLoads: 2,
    })
  })

  it('forgets the price alert when the companion is closed', async () => {
    currentPriceAlert = PRICE_ALERT
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    store.close()

    expect(store.priceAlert).toBeNull()
  })
})
