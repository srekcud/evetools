import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import {
  bomLine,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NOCXIUM_LINE_ID = 'bom-nocxium'

async function mountContributing(contribute: string) {
  route.query = { tab: 'bom', contribute }
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'member'),
    bom: [bomLine({ id: NOCXIUM_LINE_ID, typeId: 38, typeName: 'Nocxium', freeQuantity: 80 })],
    cockpit: cockpitOf('in_progress', nextAction({ code: 'source_material' })),
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project detail opened from the companion deliver action', () => {
  it('opens the contribution modal prefilled on the line of the contribute query and opens nothing for an unknown line', async () => {
    const known = await mountContributing(NOCXIUM_LINE_ID)
    const knownModal = known.findComponent(ContributeModal)
    const unknown = await mountContributing('bom-unknown')
    const unknownModal = unknown.findComponent(ContributeModal)

    expect({
      known: { show: knownModal.props('show'), prefilledLine: knownModal.props('prefilledBomItem')?.id },
      unknown: { show: unknownModal.props('show'), prefilledLine: unknownModal.props('prefilledBomItem')?.id },
    }).toEqual({
      known: { show: true, prefilledLine: NOCXIUM_LINE_ID },
      unknown: { show: false, prefilledLine: undefined },
    })
  })
})
