import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import { COMPANION_COLLAPSED_STORAGE_KEY } from '@/components/group-industry/companionCollapsedSections'
import type { BomItem } from '@/stores/group-industry/types'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionTasksOf } from './companionFixtures'

const FREE_LINE = '[data-testid="companion-free-line"]'
const MINE_LINE = '[data-testid="companion-mine-line"]'

function materialLine(index: number): BomItem {
  return bomLine({ id: `bom-material-${index}`, typeId: 1_000 + index, typeName: `Mineral ${index}`, freeQuantity: 10, estimatedPrice: 2 })
}

function jobLine(id: string, typeId: number, activityType: string | null): BomItem {
  return bomLine({ id, typeId, typeName: `Job ${id}`, isJob: true, activityType, runs: 3, freeQuantity: 1 })
}

function mountCompanion(tasks: CompanionTasks, locale: 'en' | 'fr' = 'en'): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function textOf(wrapper: VueWrapper, testId: string): string | undefined {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text().replace(/\s+/g, ' ') : undefined
}

function headerOf(wrapper: VueWrapper, testId: string): string[] {
  return wrapper.findAll(`[data-testid="${testId}"] span`).map(span => span.text())
}

function linesIn(wrapper: VueWrapper, groupTestId: string, lineSelector: string): number {
  return wrapper.findAll(`[data-testid="${groupTestId}"] ${lineSelector}`).length
}

describe('GroupCompanion activity groups', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('groups the lines to assign by activity, shows six lines in group order then the number of remaining free lines', () => {
    const free = [
      ...Array.from({ length: 5 }, (_, index) => materialLine(index)),
      jobLine('bom-manufacturing', 20, 'manufacturing'),
      jobLine('bom-reaction-1', 21, 'reaction'),
      jobLine('bom-reaction-2', 22, 'reaction'),
      jobLine('bom-copy', 23, 'copy'),
    ]

    const wrapper = mountCompanion(companionTasksOf({ free }))

    expect({
      headers: ['copy', 'reaction', 'manufacturing', 'material'].map(group => headerOf(wrapper, `companion-toggle-free-${group}`)),
      shown: ['copy', 'reaction', 'manufacturing', 'material'].map(group => linesIn(wrapper, `companion-group-free-${group}`, FREE_LINE)),
      all: wrapper.findAll(FREE_LINE).length,
      more: textOf(wrapper, 'companion-more-free'),
      moreCount: wrapper.find('[data-testid="companion-more-free"]').attributes('data-count'),
    }).toEqual({
      headers: [['Copies', '1'], ['Reactions', '2'], ['Manufacturing', '1'], ['Materials', '5']],
      shown: [1, 2, 1, 2],
      all: 6,
      more: '+ 3 more free lines · see the BOM',
      moreCount: '3',
    })
  })

  it('shows a job line without a known activity in its own unknown group, never in another one', () => {
    const free = [jobLine('bom-salvage', 30, 'salvage'), jobLine('bom-none', 31, null), jobLine('bom-reaction', 32, 'reaction')]

    const english = mountCompanion(companionTasksOf({ free }))
    const french = mountCompanion(companionTasksOf({ free }), 'fr')

    expect({
      unknown: headerOf(english, 'companion-toggle-free-unknown'),
      unknownLines: linesIn(english, 'companion-group-free-unknown', FREE_LINE),
      reactionLines: linesIn(english, 'companion-group-free-reaction', FREE_LINE),
      unknownFr: headerOf(french, 'companion-toggle-free-unknown'),
      noMore: english.find('[data-testid="companion-more-free"]').exists(),
    }).toEqual({ unknown: ['Unknown activity', '2'], unknownLines: 2, reactionLines: 1, unknownFr: ['Activité inconnue', '2'], noMore: false })
  })

  it('groups the lines to deliver by activity without capping them and translates the group names in French', () => {
    const mine = [
      ...Array.from({ length: 8 }, (_, index) => ({ item: materialLine(index), quantity: 5 })),
      { item: jobLine('bom-manufacturing', 40, 'manufacturing'), quantity: 1 },
      { item: jobLine('bom-reaction', 41, 'reaction'), quantity: 2 },
    ]

    const wrapper = mountCompanion(companionTasksOf({ mine }), 'fr')

    expect({
      headers: ['reaction', 'manufacturing', 'material'].map(group => headerOf(wrapper, `companion-toggle-mine-${group}`)),
      lines: ['reaction', 'manufacturing', 'material'].map(group => linesIn(wrapper, `companion-group-mine-${group}`, MINE_LINE)),
    }).toEqual({
      headers: [['Réactions', '1'], ['Fabrication', '1'], ['Matériaux', '8']],
      lines: [1, 1, 8],
    })
  })

  it('shows the empty message and no group when nothing is left to assign', () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [] }))

    expect({
      message: wrapper.get('[data-testid="companion-pane-free"]').text(),
      groups: wrapper.findAll('[data-testid^="companion-toggle-free-"]').length,
    }).toEqual({ message: expect.stringContaining('Every line has an owner.'), groups: 0 })
  })

  it('collapses a group on click, keeps its count visible and remembers it across mounts', async () => {
    const tasks = companionTasksOf({
      free: [materialLine(1), materialLine(2), jobLine('bom-reaction-1', 50, 'reaction'), jobLine('bom-reaction-2', 51, 'reaction')],
      mine: [{ item: materialLine(5), quantity: 2 }],
    })
    const wrapper = mountCompanion(tasks)

    await wrapper.get('[data-testid="companion-toggle-free-reaction"]').trigger('click')
    const afterClick = {
      reaction: linesIn(wrapper, 'companion-group-free-reaction', FREE_LINE),
      material: linesIn(wrapper, 'companion-group-free-material', FREE_LINE),
      reactionHeader: headerOf(wrapper, 'companion-toggle-free-reaction'),
      expanded: wrapper.get('[data-testid="companion-toggle-free-reaction"]').attributes('aria-expanded'),
      stored: localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY),
    }

    const remounted = mountCompanion(tasks)
    const afterRemount = {
      reaction: linesIn(remounted, 'companion-group-free-reaction', FREE_LINE),
      material: linesIn(remounted, 'companion-group-free-material', FREE_LINE),
      mineMaterial: linesIn(remounted, 'companion-group-mine-material', MINE_LINE),
    }

    await wrapper.get('[data-testid="companion-toggle-free-reaction"]').trigger('click')
    const afterSecondClick = { reaction: linesIn(wrapper, 'companion-group-free-reaction', FREE_LINE), stored: localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY) }

    expect({ afterClick, afterRemount, afterSecondClick }).toEqual({
      afterClick: { reaction: 0, material: 2, reactionHeader: ['Reactions', '2'], expanded: 'false', stored: '["free:reaction"]' },
      afterRemount: { reaction: 0, material: 2, mineMaterial: 1 },
      afterSecondClick: { reaction: 2, stored: '[]' },
    })
  })

  it('stays open and keeps working when the browser refuses access to the storage', async () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })

    const wrapper = mountCompanion(companionTasksOf({ free: [materialLine(1), materialLine(2)] }))
    const openedByDefault = wrapper.findAll(FREE_LINE).length
    await wrapper.get('[data-testid="companion-toggle-free-material"]').trigger('click')

    getItem.mockRestore()
    setItem.mockRestore()
    expect({ openedByDefault, linesAfterClick: wrapper.findAll(FREE_LINE).length }).toEqual({ openedByDefault: 2, linesAfterClick: 0 })
  })

  it('ignores a stored value that is not a list of known groups, including the keys of the previous layout', () => {
    const free = [materialLine(1), materialLine(2)]
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, '{"free":true}')
    const garbage = mountCompanion(companionTasksOf({ free }))
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, 'not json')
    const unparsable = mountCompanion(companionTasksOf({ free }))
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, '["free","free:buy","mine:build","unknown",42]')
    const previousLayout = mountCompanion(companionTasksOf({ free }))
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, '["free:material","unknown",42]')
    const partlyKnown = mountCompanion(companionTasksOf({ free }))

    expect({
      garbage: garbage.findAll(FREE_LINE).length,
      unparsable: unparsable.findAll(FREE_LINE).length,
      previousLayout: previousLayout.findAll(FREE_LINE).length,
      partlyKnown: partlyKnown.findAll(FREE_LINE).length,
    }).toEqual({ garbage: 2, unparsable: 2, previousLayout: 2, partlyKnown: 0 })
  })
})
