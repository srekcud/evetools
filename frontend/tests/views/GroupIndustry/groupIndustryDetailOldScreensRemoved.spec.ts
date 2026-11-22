import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectInventionExclusionToggle from '@/components/group-industry/ProjectInventionExclusionToggle.vue'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import ProjectStructureHealth from '@/components/group-industry/ProjectStructureHealth.vue'
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

const OLD_BUY_OR_BUILD = { name: 'ProjectBuyOrBuild' }
const OLD_STRUCTURES = { name: 'ProjectStructures' }

async function mountOwnerNyxOn(tab: string, status: 'draft' | 'in_progress') {
  route.query = { tab }
  return mountCockpitDetail({
    project: { ...nyxProject(status, 'owner'), blacklistTypeIds: [11399, 11400] },
    cockpit: cockpitOf(status, status === 'draft'
      ? nextAction({ code: 'start_project', targetStatus: 'in_progress' })
      : nextAction({ code: 'launch_job', tab: 'materials' })),
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

describe('old buy or build and stations screens', () => {
  it.each([
    ['bom', 'draft'],
    ['bom', 'in_progress'],
    ['chain', 'draft'],
    ['chain', 'in_progress'],
  ] as const)('are not rendered on the %s tab of a %s project', async (tab, status) => {
    const wrapper = await mountOwnerNyxOn(tab, status)

    expect({
      buyOrBuild: wrapper.findComponent(OLD_BUY_OR_BUILD).exists(),
      structures: wrapper.findComponent(OLD_STRUCTURES).exists(),
    }).toEqual({ buyOrBuild: false, structures: false })
  })

  it('keeps the chain, research and structure health cards on the production tab', async () => {
    const production = await mountOwnerNyxOn('chain', 'in_progress')

    expect({
      chain: production.findComponent(ProductionChain).exists(),
      research: production.findComponent(ProjectResearch).exists(),
      structureHealth: production.findComponent(ProjectStructureHealth).exists(),
    }).toEqual({ chain: true, research: true, structureHealth: true })
  })

  it('keeps the invention toggle above the materials table on the sourcing tab', async () => {
    const sourcing = await mountOwnerNyxOn('bom', 'in_progress')
    const toggle = sourcing.findComponent(ProjectInventionExclusionToggle)
    const table = sourcing.findComponent(BomTable)

    expect(toggle.exists() && table.exists() && precedes(toggle.element, table.element)).toBe(true)
  })
})
