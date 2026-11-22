import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import MaterialSourcing from '@/components/group-industry/MaterialSourcing.vue'
import ProjectCourier from '@/components/group-industry/ProjectCourier.vue'
import ProjectInventionExclusionToggle from '@/components/group-industry/ProjectInventionExclusionToggle.vue'
import ProjectPurchasesToDeliver from '@/components/group-industry/ProjectPurchasesToDeliver.vue'
import { apiRequest } from '@/services/api'
import { cockpitOf, mountCockpitDetail, nextAction, nyxProject, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountSourcingWithContainers() {
  route.query = { tab: 'bom' }
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { containerNames: ['Nyx cans'] }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials' })),
  })
}

function precedes(first: Element, second: Element): boolean {
  return Boolean(first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('sourcing tab card order', () => {
  it('lists the materials table, where to buy, purchases to deliver, the courier and the containers in that order', async () => {
    const sourcing = await mountSourcingWithContainers()
    const cards = [
      ['materials table', sourcing.findComponent(BomTable)],
      ['where to buy', sourcing.findComponent(MaterialSourcing)],
      ['purchases to deliver', sourcing.findComponent(ProjectPurchasesToDeliver)],
      ['courier', sourcing.findComponent(ProjectCourier)],
      ['containers', sourcing.findComponent(ContainerBanner)],
    ] as const

    const orderedPairs = cards.slice(1).map(([name, card], index) => ({
      after: cards[index][0],
      name,
      exists: card.exists() && cards[index][1].exists(),
      inOrder: card.exists() && cards[index][1].exists() && precedes(cards[index][1].element, card.element),
    }))

    expect(orderedPairs.filter(pair => !pair.exists || !pair.inOrder)).toEqual([])
  })

  it('keeps the preparation cards above the materials table', async () => {
    const sourcing = await mountSourcingWithContainers()
    const invention = sourcing.findComponent(ProjectInventionExclusionToggle)
    const table = sourcing.findComponent(BomTable)

    expect(invention.exists() && precedes(invention.element, table.element)).toBe(true)
  })
})
