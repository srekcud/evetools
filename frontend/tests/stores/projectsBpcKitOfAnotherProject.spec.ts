import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { BpcKit } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_PROJECT_ID = 'sabre-project'
const ISHTAR_PROJECT_ID = 'ishtar-project'

const SABRE_BPC_KIT: BpcKit = {
  id: SABRE_PROJECT_ID,
  isT2: true,
  inventions: [],
  summary: { totalInventionCost: 6_900, bestDecryptorTypeId: null, totalBpcKitCost: 6_900 },
}

const ISHTAR_BPC_KIT: BpcKit = {
  id: ISHTAR_PROJECT_ID,
  isT2: true,
  inventions: [],
  summary: { totalInventionCost: 412_500, bestDecryptorTypeId: 34203, totalBpcKitCost: 437_800 },
}

function bpcKitPath(projectId: string): string {
  return `/industry/projects/${projectId}/bpc-kit`
}

async function storeWithTheSabreBpcKitLoaded(): Promise<ReturnType<typeof useProjectsStore>> {
  const store = useProjectsStore()
  vi.mocked(apiRequest).mockResolvedValueOnce(SABRE_BPC_KIT)
  await store.fetchBpcKit(SABRE_PROJECT_ID)
  expect(store.bpcKit).toEqual(SABRE_BPC_KIT)
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('projects store BPC kit when another project is opened', () => {
  it('holds no BPC kit while the BPC kit of the newly opened project is loading', async () => {
    const store = await storeWithTheSabreBpcKitLoaded()
    vi.mocked(apiRequest).mockReturnValueOnce(new Promise<never>(() => {}))

    void store.fetchBpcKit(ISHTAR_PROJECT_ID)

    expect(apiRequest).toHaveBeenLastCalledWith(bpcKitPath(ISHTAR_PROJECT_ID))
    expect(store.bpcKit).toBeNull()
  })

  it('holds no BPC kit when the BPC kit of the newly opened project fails to load', async () => {
    const store = await storeWithTheSabreBpcKitLoaded()
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('ESI unavailable'))

    const fetched = await store.fetchBpcKit(ISHTAR_PROJECT_ID)

    expect(fetched).toBeNull()
    expect(store.bpcKit).toBeNull()
  })

  it('holds the BPC kit of the newly opened project once it is loaded', async () => {
    const store = await storeWithTheSabreBpcKitLoaded()
    vi.mocked(apiRequest).mockResolvedValueOnce(ISHTAR_BPC_KIT)

    await store.fetchBpcKit(ISHTAR_PROJECT_ID)

    expect(store.bpcKit).toEqual(ISHTAR_BPC_KIT)
  })
})
