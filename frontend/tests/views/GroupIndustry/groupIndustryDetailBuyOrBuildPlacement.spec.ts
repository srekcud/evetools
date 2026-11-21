import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectBuyOrBuild from '@/components/group-industry/ProjectBuyOrBuild.vue'
import ProjectInventionExclusionToggle from '@/components/group-industry/ProjectInventionExclusionToggle.vue'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import ProjectStructureHealth from '@/components/group-industry/ProjectStructureHealth.vue'
import ProjectStructures from '@/components/group-industry/ProjectStructures.vue'
import { apiRequest } from '@/services/api'
import { cockpitOf, mountCockpitDetail, nextAction, nyxProject, PROJECT_ID, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountOwnerNyxOn(tab: string) {
  route.query = { tab }
  return mountCockpitDetail({
    project: { ...nyxProject('in_progress', 'owner'), blacklistTypeIds: [11399, 11400] },
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

describe('project buy or build card placement', () => {
  it('mounts the buy or build card on the sourcing tab with the blacklist and the switch right of the owner', async () => {
    const sourcing = await mountOwnerNyxOn('bom')
    const card = sourcing.findComponent(ProjectBuyOrBuild)

    expect({
      mounted: card.exists(),
      props: card.exists() ? card.props() : 'absent',
    }).toEqual({
      mounted: true,
      props: { projectId: PROJECT_ID, canSwitch: true, blacklistTypeIds: [11399, 11400] },
    })
  })

  it('places the card after the invention toggle and before the materials table', async () => {
    const sourcing = await mountOwnerNyxOn('bom')
    const card = sourcing.findComponent(ProjectBuyOrBuild)
    const toggle = sourcing.findComponent(ProjectInventionExclusionToggle)
    const table = sourcing.findComponent(BomTable)

    expect(card.exists() && precedes(toggle.element, card.element) && precedes(card.element, table.element)).toBe(true)
  })

  it('removes the card from the production tab and keeps the chain, research, structures and health cards', async () => {
    const production = await mountOwnerNyxOn('chain')

    expect({
      buyOrBuild: production.findComponent(ProjectBuyOrBuild).exists(),
      chain: production.findComponent(ProductionChain).exists(),
      research: production.findComponent(ProjectResearch).exists(),
      structures: production.findComponent(ProjectStructures).exists(),
      structureHealth: production.findComponent(ProjectStructureHealth).exists(),
    }).toEqual({ buyOrBuild: false, chain: true, research: true, structures: true, structureHealth: true })
  })

  it('reloads the plan when a switch made from the sourcing tab completes', async () => {
    const sourcing = await mountOwnerNyxOn('bom')
    const card = sourcing.findComponent(ProjectBuyOrBuild)
    vi.mocked(apiRequest).mockClear()

    card.vm.$emit('switched')
    await sourcing.vm.$nextTick()

    expect(vi.mocked(apiRequest).mock.calls.length).toBeGreaterThan(0)
  })
})
