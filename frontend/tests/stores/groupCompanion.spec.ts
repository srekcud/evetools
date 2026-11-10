import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import type { BomItem } from '@/stores/group-industry/types'
import { bomLine, companionProject, COMPANION_PROJECT_ID } from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const BOM_PATH = `${PROJECT_PATH}/bom`
const RESERVATION_PATH = `${BOM_PATH}/bom-nocxium/reservation`

let currentBom: BomItem[]

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
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === PROJECT_PATH) return companionProject()
    if (endpoint === BOM_PATH) return currentBom
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
})
