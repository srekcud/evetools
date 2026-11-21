import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import ProjectBlacklistEditor from '@/components/group-industry/ProjectBlacklistEditor.vue'
import ProjectBuyOrBuild from '@/components/group-industry/ProjectBuyOrBuild.vue'
import ProjectInventionExclusionToggle from '@/components/group-industry/ProjectInventionExclusionToggle.vue'
import ProjectStructureHealth from '@/components/group-industry/ProjectStructureHealth.vue'
import ProjectStructures from '@/components/group-industry/ProjectStructures.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import { expectOnlyLocaleTexts, TEST_LOCALES } from '../../localeTexts'
import { cockpitOf, mountCockpitDetail, nextAction, nyxProject, route, textOf } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
  useRoute: (() => route) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PREPARE_BLOCK = '[data-testid="prepare-block"]'

async function mountOn(tab: string, status: GroupProjectStatus, role: GroupMemberRole = 'owner', locale: 'en' | 'fr' = 'en') {
  route.query = { tab }
  return mountCockpitDetail({
    project: nyxProject(status, role),
    cockpit: cockpitOf(status, status === 'draft'
      ? nextAction({ code: 'start_project', targetStatus: 'in_progress' })
      : nextAction({ code: 'launch_job', tab: 'materials' })),
  }, locale)
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

describe('group project detail prepare block', () => {
  it('groups the blacklist, the invention exclusion, buy or build and the structures in one block of the sourcing tab in draft', async () => {
    const wrapper = await mountOn('bom', 'draft')
    const block = wrapper.find(PREPARE_BLOCK)
    const inside = (component: Parameters<typeof wrapper.findComponent>[0]) => {
      const found = wrapper.findComponent(component)
      return found.exists() && block.exists() && block.element.contains(found.element)
    }

    expect({
      block: block.exists(),
      blacklist: inside(ProjectBlacklistEditor),
      invention: inside(ProjectInventionExclusionToggle),
      buyOrBuild: inside(ProjectBuyOrBuild),
      structures: inside(ProjectStructures),
    }).toEqual({ block: true, blacklist: true, invention: true, buyOrBuild: true, structures: true })
  })

  it('orders the block content blacklist, invention, buy or build, structures, then the materials table', async () => {
    const wrapper = await mountOn('bom', 'draft')
    const elements = [ProjectBlacklistEditor, ProjectInventionExclusionToggle, ProjectBuyOrBuild, ProjectStructures, BomTable]
      .map(component => wrapper.findComponent(component).element)

    expect(elements.slice(1).every((element, index) => precedes(elements[index], element))).toBe(true)
  })

  it('titles the block and says what starting locks, in both languages', async () => {
    for (const locale of TEST_LOCALES) {
      const wrapper = await mountOn('bom', 'draft', 'owner', locale)

      expectOnlyLocaleTexts(textOf(wrapper, PREPARE_BLOCK), locale, ['groupIndustry.prepare.title', 'groupIndustry.prepare.help'])
      wrapper.unmount()
      setActivePinia(createPinia())
    }
  })

  it('removes the structures from the production tab in draft and keeps the other production cards', async () => {
    const wrapper = await mountOn('chain', 'draft')

    expect({
      structures: wrapper.findComponent(ProjectStructures).exists(),
      structureHealth: wrapper.findComponent(ProjectStructureHealth).exists(),
      block: wrapper.find(PREPARE_BLOCK).exists(),
    }).toEqual({ structures: false, structureHealth: true, block: false })
  })

  it('shows the block to a member who cannot edit, with the children switched off', async () => {
    const wrapper = await mountOn('bom', 'draft', 'member')

    expect({
      block: wrapper.find(PREPARE_BLOCK).exists(),
      buyOrBuildCanSwitch: wrapper.findComponent(ProjectBuyOrBuild).props('canSwitch'),
      structuresCanSwitch: wrapper.findComponent(ProjectStructures).props('canSwitch'),
    }).toEqual({ block: true, buyOrBuildCanSwitch: false, structuresCanSwitch: false })
  })

  it.each(['published', 'in_progress', 'selling', 'completed'] as const)('does not render the block once the project is %s', async (status) => {
    const wrapper = await mountOn('bom', status)

    expect({
      block: wrapper.find(PREPARE_BLOCK).exists(),
      buyOrBuild: wrapper.findComponent(ProjectBuyOrBuild).exists(),
      structuresInSourcing: wrapper.findComponent(ProjectStructures).exists(),
    }).toEqual({ block: false, buyOrBuild: true, structuresInSourcing: false })
  })

  it('keeps the structures in the production tab and buy or build before the materials table once started', async () => {
    const production = await mountOn('chain', 'in_progress')
    const structuresInProduction = production.findComponent(ProjectStructures).exists()
    production.unmount()
    setActivePinia(createPinia())
    const sourcing = await mountOn('bom', 'in_progress')

    expect({
      structuresInProduction,
      buyOrBuildBeforeTable: precedes(sourcing.findComponent(ProjectBuyOrBuild).element, sourcing.findComponent(BomTable).element),
    }).toEqual({ structuresInProduction: true, buyOrBuildBeforeTable: true })
  })
})
