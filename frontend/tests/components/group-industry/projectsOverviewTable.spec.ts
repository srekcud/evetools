import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectsOverviewTable from '@/components/group-industry/ProjectsOverviewTable.vue'
import type { GroupProject, ProjectOverview } from '@/stores/group-industry/types'

const ISHTAR_ID = 'project-ishtar'
const HECATE_ID = 'project-hecate'
const DRAFT_ID = 'project-ferox-draft'

function project(id: string, overrides: Partial<GroupProject>): GroupProject {
  return {
    id,
    name: null,
    status: 'in_progress',
    items: [],
    ownerCharacterName: 'Aria Solberg',
    myRole: 'owner',
    myStatus: 'accepted',
    ...overrides,
  } as unknown as GroupProject
}

const ISHTAR_PROJECT = project(ISHTAR_ID, { name: 'Ishtar batch', items: [{ typeId: 12005, typeName: 'Ishtar', meLevel: 10, teLevel: 20, runs: 100 }] })
const HECATE_PROJECT = project(HECATE_ID, { name: 'Hecate batch', myRole: 'admin', ownerCharacterName: 'Brann Holt', status: 'selling' })
const DRAFT_PROJECT = project(DRAFT_ID, { name: 'Ferox batch', status: 'draft', myRole: 'member', ownerCharacterName: 'Corin Vale' })
const COMPLETED_PROJECT = project('project-completed', { name: 'Jackdaw batch', status: 'completed' })

function overview(projectId: string, overrides: Partial<ProjectOverview>): ProjectOverview {
  return {
    projectId,
    myRole: 'owner',
    ownerName: 'Aria Solberg',
    team: [],
    flow: { toBring: 0, onSite: 0, inProduction: 0, out: 0, done: 0 },
    flowReason: null,
    flowUnknown: 0,
    awaited: [],
    ...overrides,
  }
}

function mountTable(projects: GroupProject[], overviews: ProjectOverview[], overviewError: string | null = null): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(ProjectsOverviewTable, { props: { projects, overviews, overviewError }, global: { plugins: [i18n] } })
}

function cell(wrapper: VueWrapper, projectId: string, column: string) {
  const found = wrapper.find(`tr[data-project="${projectId}"] [data-column="${column}"]`)
  if (!found.exists()) throw new Error(`cell ${column} of ${projectId} not found`)
  return found
}

describe('ProjectsOverviewTable', () => {
  it('shows one row per project under the columns of the flow', () => {
    const wrapper = mountTable([ISHTAR_PROJECT, HECATE_PROJECT], [overview(ISHTAR_ID, {}), overview(HECATE_ID, {})])

    expect(wrapper.findAll('th').map(header => header.text())).toEqual([
      'Project', 'You', 'Owner · team', 'To bring', 'On site', 'In production', 'Out', 'Sold / handed over', 'Who is awaited',
    ])
    expect(wrapper.findAll('tr[data-project]').map(row => row.attributes('data-project'))).toEqual([ISHTAR_ID, HECATE_ID])
    expect(cell(wrapper, ISHTAR_ID, 'project').text()).toContain('Ishtar batch')
    expect(cell(wrapper, ISHTAR_ID, 'project').text()).toContain('100 runs')
  })

  it('opens the project of the clicked row', async () => {
    const wrapper = mountTable([ISHTAR_PROJECT, HECATE_PROJECT], [overview(ISHTAR_ID, {}), overview(HECATE_ID, {})])

    await wrapper.find(`tr[data-project="${HECATE_ID}"]`).trigger('click')

    expect(wrapper.emitted('open')).toEqual([[HECATE_PROJECT]])
  })

  it('shows my role and the owner with the team of each project', () => {
    const wrapper = mountTable(
      [ISHTAR_PROJECT, HECATE_PROJECT],
      [
        overview(ISHTAR_ID, { team: [{ name: 'Brann Holt', role: 'member' }, { name: 'Corin Vale', role: 'admin' }] }),
        overview(HECATE_ID, { myRole: 'admin', ownerName: 'Brann Holt' }),
      ],
    )

    expect(cell(wrapper, ISHTAR_ID, 'role').text()).toBe('Owner')
    expect(cell(wrapper, HECATE_ID, 'role').text()).toBe('Admin')
    expect(cell(wrapper, ISHTAR_ID, 'team').text()).toBe('Aria Solberg · Brann Holt, Corin Vale')
    expect(cell(wrapper, HECATE_ID, 'team').text()).toBe('Brann Holt')
  })

  it('shows the number of cards of each column and lights up the ones that are not empty', () => {
    const wrapper = mountTable([ISHTAR_PROJECT], [overview(ISHTAR_ID, { flow: { toBring: 3, onSite: 0, inProduction: 12, out: 1, done: 0 } })])

    expect(['toBring', 'onSite', 'inProduction', 'out', 'done'].map(column => cell(wrapper, ISHTAR_ID, column).text())).toEqual(['3', '0', '12', '1', '0'])
    expect(cell(wrapper, ISHTAR_ID, 'toBring').classes()).toContain('flow-cell-active')
    expect(cell(wrapper, ISHTAR_ID, 'onSite').classes()).toContain('flow-cell-empty')
  })

  it('marks the cards that could not be read next to the count, never as zero', () => {
    const wrapper = mountTable([ISHTAR_PROJECT], [overview(ISHTAR_ID, { flow: { toBring: 3, onSite: 0, inProduction: 0, out: 0, done: 0 }, flowUnknown: 2 })])

    expect(cell(wrapper, ISHTAR_ID, 'toBring').text()).toBe('3 + 2 ?')
    expect(cell(wrapper, ISHTAR_ID, 'onSite').text()).toBe('0 + 2 ?')
  })

  it('shows a question mark with its cause when the flow cannot be read, and a dash for a draft', () => {
    const wrapper = mountTable(
      [ISHTAR_PROJECT, DRAFT_PROJECT],
      [
        overview(ISHTAR_ID, { flow: null, flowReason: 'plan_unavailable' }),
        overview(DRAFT_ID, { myRole: 'member', ownerName: 'Corin Vale', flow: null, flowReason: 'not_started' }),
      ],
    )

    expect(cell(wrapper, ISHTAR_ID, 'toBring').text()).toBe('?')
    expect(cell(wrapper, ISHTAR_ID, 'toBring').attributes('title')).toBe('The production plan cannot be read yet')
    expect(cell(wrapper, DRAFT_ID, 'toBring').text()).toBe('—')
  })

  it('shows who is awaited with the connected pilot first, or the ESI when nobody is', () => {
    const wrapper = mountTable(
      [ISHTAR_PROJECT, HECATE_PROJECT],
      [
        overview(ISHTAR_ID, { awaited: [{ name: 'Aria Solberg', mine: true, count: 3 }, { name: 'Brann Holt', mine: false, count: 5 }] }),
        overview(HECATE_ID, { myRole: 'admin', ownerName: 'Brann Holt' }),
      ],
    )

    expect(cell(wrapper, ISHTAR_ID, 'awaited').text()).toBe('you 3 · Brann Holt 5')
    expect(cell(wrapper, ISHTAR_ID, 'awaited').find('.awaited-mine').text()).toBe('you 3')
    expect(cell(wrapper, HECATE_ID, 'awaited').text()).toBe('ESI (jobs, couriers)')
  })

  it('says a completed project is closed instead of who is awaited', () => {
    const wrapper = mountTable([COMPLETED_PROJECT], [overview('project-completed', { flow: null, flowReason: 'completed' })])

    expect(cell(wrapper, 'project-completed', 'awaited').text()).toBe('closed')
  })

  it('keeps the rows and the question marks when the overview could not be loaded', () => {
    const wrapper = mountTable([ISHTAR_PROJECT, HECATE_PROJECT], [], 'Service unavailable')

    expect(wrapper.findAll('tr[data-project]')).toHaveLength(2)
    expect(cell(wrapper, ISHTAR_ID, 'role').text()).toBe('Owner')
    expect(cell(wrapper, HECATE_ID, 'role').text()).toBe('Admin')
    expect(cell(wrapper, HECATE_ID, 'team').text()).toBe('Brann Holt')
    expect(cell(wrapper, ISHTAR_ID, 'inProduction').text()).toBe('?')
    expect(cell(wrapper, ISHTAR_ID, 'inProduction').attributes('title')).toBe('Service unavailable')
  })
})
