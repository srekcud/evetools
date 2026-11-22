import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import { buildCompanionTasks, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionProject, companionTasksOf, jobMatch } from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const TRITANIUM_REMAINING = 4_000
const MORPHITE_REMAINING = 300
const FEROX_RUNS = 3

function soloProject(overrides: Partial<GroupProject> = {}): GroupProject {
  return companionProject({ solo: true, myRole: 'owner', membersCount: 1, ...overrides })
}

function tasksOf(bomItems: BomItem[], project: GroupProject): CompanionTasks {
  return buildCompanionTasks({
    bomItems,
    chainNodes: null,
    project,
    members: [],
    myCharacterNames: ['Selene Vance'],
    priceAlert: null,
    undercutOrders: [],
    unassignedJobCount: null,
    now: NOW,
  })
}

function freeMaterial(id: string, typeId: number, remaining: number): BomItem {
  return bomLine({ id, typeId, typeName: `Type ${typeId}`, requiredQuantity: remaining, remainingQuantity: remaining, freeQuantity: remaining })
}

function mountCompanion(tasks: CompanionTasks, size: 'column' | 'bar' = 'column', locale: 'en' | 'fr' = 'en'): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size, errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function paneIsShown(wrapper: VueWrapper, pane: string): boolean {
  const element = wrapper.find(`[data-testid="companion-pane-${pane}"]`)
  return element.exists() && element.attributes('style') !== 'display: none;'
}

describe('Companion tasks of a solo project', () => {
  it('lists every unfulfilled line as the owner line to deliver, with its remaining quantity, and nothing to assign', () => {
    const tritanium = freeMaterial('bom-tritanium', 34, TRITANIUM_REMAINING)
    const morphite = freeMaterial('bom-morphite', 11_399, MORPHITE_REMAINING)
    const ferox = bomLine({ id: 'bom-ferox', typeId: 16_227, typeName: 'Ferox', isJob: true, activityType: 'manufacturing', runs: FEROX_RUNS, requiredQuantity: FEROX_RUNS, remainingQuantity: FEROX_RUNS, freeQuantity: FEROX_RUNS })

    const tasks = tasksOf([tritanium, morphite, ferox], soloProject())

    expect({
      solo: tasks.solo,
      free: tasks.free.map(item => item.id),
      mine: tasks.mine.map(entry => [entry.item.id, entry.quantity]),
    }).toEqual({
      solo: true,
      free: [],
      mine: [['bom-tritanium', TRITANIUM_REMAINING], ['bom-morphite', MORPHITE_REMAINING], ['bom-ferox', FEROX_RUNS]],
    })
  })

  it('leaves out a fulfilled line, a line without quantity left and a job line a running job already works', () => {
    const fulfilled = bomLine({ id: 'bom-fulfilled', typeId: 34, typeName: 'Tritanium', isFulfilled: true, remainingQuantity: 0, freeQuantity: 0 })
    const empty = bomLine({ id: 'bom-empty', typeId: 35, typeName: 'Pyerite', remainingQuantity: 0, freeQuantity: 0 })
    const worked = bomLine({
      id: 'bom-worked',
      typeId: 16_227,
      typeName: 'Ferox',
      isJob: true,
      runs: FEROX_RUNS,
      remainingQuantity: FEROX_RUNS,
      freeQuantity: FEROX_RUNS,
      jobMatches: [jobMatch({ id: 'match-1', esiJobId: 9_001, status: 'active' })],
    })
    const open = freeMaterial('bom-open', 36, TRITANIUM_REMAINING)

    const tasks = tasksOf([fulfilled, empty, worked, open], soloProject())

    expect(tasks.mine.map(entry => entry.item.id)).toEqual(['bom-open'])
  })

  it('ignores a stored reservation of the owner and shows the whole remaining quantity once', () => {
    const line = freeMaterial('bom-tritanium', 34, TRITANIUM_REMAINING)
    line.reservations = [{ participantId: 'owner', characterName: 'Selene Vance', quantity: 1_000, expiresAt: '2026-11-23T12:00:00+00:00', isMine: true }]
    line.reservedQuantity = 1_000
    line.freeQuantity = TRITANIUM_REMAINING - 1_000

    const tasks = tasksOf([line], soloProject())

    expect(tasks.mine.map(entry => [entry.item.id, entry.quantity])).toEqual([['bom-tritanium', TRITANIUM_REMAINING]])
  })

  it('keeps the lines to assign of a group project and only its stored reservations to deliver', () => {
    const toAssign = freeMaterial('bom-tritanium', 34, TRITANIUM_REMAINING)
    const reserved = freeMaterial('bom-morphite', 11_399, MORPHITE_REMAINING)
    reserved.reservations = [{ participantId: 'me', characterName: 'Selene Vance', quantity: 100, expiresAt: '2026-11-23T12:00:00+00:00', isMine: true }]
    reserved.reservedQuantity = 100
    reserved.freeQuantity = MORPHITE_REMAINING - 100

    const tasks = tasksOf([toAssign, reserved], companionProject({ solo: false }))

    expect({
      solo: tasks.solo,
      free: tasks.free.map(item => item.id),
      mine: tasks.mine.map(entry => [entry.item.id, entry.quantity]),
    }).toEqual({ solo: false, free: ['bom-tritanium', 'bom-morphite'], mine: [['bom-morphite', 100]] })
  })
})

describe('GroupCompanion on a solo project', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  const soloLines = [freeMaterial('bom-tritanium', 34, TRITANIUM_REMAINING), freeMaterial('bom-morphite', 11_399, MORPHITE_REMAINING)]

  function soloTasks(): CompanionTasks {
    return tasksOf(soloLines, soloProject())
  }

  it('shows no To assign tab and no I will handle it button, and opens on the lines to deliver', () => {
    const wrapper = mountCompanion(soloTasks())

    expect({
      tabs: wrapper.findAll('[role="tab"]').map(tab => tab.attributes('data-testid')),
      freePane: wrapper.find('[data-testid="companion-pane-free"]').exists(),
      claimButtons: wrapper.findAll('[data-testid="companion-claim"]').length,
      minePaneShown: paneIsShown(wrapper, 'mine'),
      mineLines: wrapper.findAll('[data-testid="companion-mine-line"]').length,
      columns: wrapper.get('[role="tablist"]').classes().includes('grid-cols-3'),
    }).toEqual({
      tabs: ['companion-tab-mine', 'companion-tab-transport', 'companion-tab-jobs'],
      freePane: false,
      claimButtons: 0,
      minePaneShown: true,
      mineLines: 2,
      columns: true,
    })
  })

  it('offers no release on a line the owner never reserved, and no free value in the footer', () => {
    const wrapper = mountCompanion(soloTasks())

    expect({
      releaseButtons: wrapper.findAll('[data-testid="companion-release"]').length,
      freeValue: wrapper.find('[data-testid="companion-footer-free-value"]').exists(),
    }).toEqual({ releaseButtons: 0, freeValue: false })
  })

  it('hides the To assign counter of the bar', () => {
    const wrapper = mountCompanion(soloTasks(), 'bar')

    expect({
      free: wrapper.find('[data-testid="companion-count-free"]').exists(),
      mine: wrapper.find('[data-testid="companion-count-mine"]').exists(),
    }).toEqual({ free: false, mine: true })
  })

  it('keeps the To assign tab, the claim button and the release button on a group project', () => {
    const reserved = freeMaterial('bom-morphite', 11_399, MORPHITE_REMAINING)
    reserved.reservations = [{ participantId: 'me', characterName: 'Selene Vance', quantity: 100, expiresAt: '2026-11-23T12:00:00+00:00', isMine: true }]
    const tasks = companionTasksOf({ solo: false, free: [freeMaterial('bom-tritanium', 34, TRITANIUM_REMAINING)], mine: [{ item: reserved, quantity: 100 }] })

    const wrapper = mountCompanion(tasks)

    expect({
      tabs: wrapper.findAll('[role="tab"]').map(tab => tab.attributes('data-testid')),
      claimButtons: wrapper.findAll('[data-testid="companion-claim"]').length,
      releaseButtons: wrapper.findAll('[data-testid="companion-release"]').length,
    }).toEqual({ tabs: ['companion-tab-free', 'companion-tab-mine', 'companion-tab-transport', 'companion-tab-jobs'], claimButtons: 1, releaseButtons: 1 })
  })
})
