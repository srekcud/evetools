import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import type { StructureConfig } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const RAITARU_PERIMETER: StructureConfig = {
  id: 'struct-1',
  name: 'Raitaru Perimeter',
  locationId: 1_035_000_000_011,
  solarSystemId: 30_000_144,
  securityType: 'highsec',
  structureType: 'raitaru',
  rigs: ['Standard M-Set Equipment Manufacturing Material Efficiency I'],
  isDefault: false,
  isCorporationStructure: false,
  manufacturingMaterialBonus: 2,
  reactionMaterialBonus: 0,
  manufacturingTimeBonus: 15,
  reactionTimeBonus: 0,
  facilityTaxRate: 0.5,
  createdAt: '2026-10-20T09:00:00+00:00',
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('structures store failure (issue 189)', () => {
  it('rethrows the failure of the structures load and keeps the structures already loaded', async () => {
    const store = useStructuresStore()
    store.structures = [RAITARU_PERIMETER]
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await expect(store.fetchStructures()).rejects.toThrow('API error: 503')
    expect(store.structures).toEqual([RAITARU_PERIMETER])
  })

  it('rethrows the failure of a structure deletion and keeps the structure', async () => {
    const store = useStructuresStore()
    store.structures = [RAITARU_PERIMETER]
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 500', 500))

    await expect(store.deleteStructure(RAITARU_PERIMETER.id)).rejects.toThrow('API error: 500')
    expect(store.structures).toEqual([RAITARU_PERIMETER])
  })

  it('exposes no shared error field, the screens show their own failures', () => {
    expect(useStructuresStore()).not.toHaveProperty('error')
  })
})
