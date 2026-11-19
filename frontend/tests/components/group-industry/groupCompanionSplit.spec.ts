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

function purchase(index: number): BomItem {
  return bomLine({ id: `bom-buy-${index}`, typeId: 1_000 + index, typeName: `Mineral ${index}`, freeQuantity: 10, estimatedPrice: 2 })
}

function craft(index: number): BomItem {
  return bomLine({ id: `bom-build-${index}`, typeId: 2_000 + index, typeName: `Hull ${index}`, isJob: true, activityType: 'manufacturing', runs: 3, freeQuantity: 1 })
}

function purchases(count: number): BomItem[] {
  return Array.from({ length: count }, (_, index) => purchase(index))
}

function crafts(count: number): BomItem[] {
  return Array.from({ length: count }, (_, index) => craft(index))
}

function mountCompanion(tasks: CompanionTasks, options: { size?: 'bar' | 'column'; locale?: 'en' | 'fr' } = {}): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: options.size ?? 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })] },
  })
}

function textOf(wrapper: VueWrapper, testId: string): string | undefined {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text().replace(/\s+/g, ' ') : undefined
}

function headerOf(wrapper: VueWrapper, testId: string): string[] {
  return wrapper.findAll(`[data-testid="${testId}"] span`).map(span => span.text())
}

function namesIn(wrapper: VueWrapper, groupTestId: string, lineSelector: string): string[] {
  return wrapper.findAll(`[data-testid="${groupTestId}"] ${lineSelector}`).map(line => line.text())
}

describe('GroupCompanion purchases and crafts split', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('splits To assign into To buy and To build, six lines each, with their own count and remaining link', () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [...purchases(9), ...crafts(7)] }))

    expect({
      buyHeader: headerOf(wrapper, 'companion-toggle-free-buy'),
      buildHeader: headerOf(wrapper, 'companion-toggle-free-build'),
      buyLines: namesIn(wrapper, 'companion-free-buy', FREE_LINE).length,
      buildLines: namesIn(wrapper, 'companion-free-build', FREE_LINE).length,
      moreBuy: textOf(wrapper, 'companion-more-free-buy'),
      moreBuild: textOf(wrapper, 'companion-more-free-build'),
      sectionTotal: textOf(wrapper, 'companion-free-total'),
    }).toEqual({
      buyHeader: ['To buy', '9'],
      buildHeader: ['To build', '7'],
      buyLines: 6,
      buildLines: 6,
      moreBuy: '+ 3 more to buy · see the BOM',
      moreBuild: '+ 1 more to build · see the BOM',
      sectionTotal: '16',
    })
  })

  it('keeps purchases out of To build and crafts out of To buy', () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [purchase(1), craft(1)] }))

    expect({
      buy: namesIn(wrapper, 'companion-free-buy', FREE_LINE).map(text => text.includes('Mineral 1')),
      build: namesIn(wrapper, 'companion-free-build', FREE_LINE).map(text => text.includes('Hull 1')),
    }).toEqual({ buy: [true], build: [true] })
  })

  it('hides an empty sub-group and shows the empty message only when nothing is left to assign', () => {
    const onlyPurchases = mountCompanion(companionTasksOf({ free: purchases(2) }))
    const nothing = mountCompanion(companionTasksOf({ free: [] }))

    expect({
      buy: onlyPurchases.find('[data-testid="companion-free-buy"]').exists(),
      build: onlyPurchases.find('[data-testid="companion-free-build"]').exists(),
      emptyMessage: nothing.get('[data-testid="companion-free"]').text(),
      noGroups: nothing.findAll('[data-testid^="companion-toggle-free-"]').length,
    }).toEqual({ buy: true, build: false, emptyMessage: expect.stringContaining('Every line has an owner.'), noGroups: 0 })
  })

  it('splits My lines to deliver into To buy and To build without capping the lines', () => {
    const mine = [
      ...purchases(8).map(item => ({ item, quantity: 5 })),
      ...crafts(2).map(item => ({ item, quantity: 1 })),
    ]

    const wrapper = mountCompanion(companionTasksOf({ mine }))

    expect({
      buyHeader: headerOf(wrapper, 'companion-toggle-mine-buy'),
      buildHeader: headerOf(wrapper, 'companion-toggle-mine-build'),
      buyLines: namesIn(wrapper, 'companion-mine-buy', MINE_LINE).length,
      buildLines: namesIn(wrapper, 'companion-mine-build', MINE_LINE).length,
      sectionTotal: textOf(wrapper, 'companion-mine-total'),
    }).toEqual({ buyHeader: ['To buy', '8'], buildHeader: ['To build', '2'], buyLines: 8, buildLines: 2, sectionTotal: '10' })
  })

  it('writes the bar counters with a purchases side and a crafts side and hides a side at zero', () => {
    const both = mountCompanion(companionTasksOf({ free: [...purchases(58), ...crafts(11)], mine: [{ item: purchase(1), quantity: 3 }, { item: craft(1), quantity: 1 }] }), { size: 'bar' })
    const purchasesOnly = mountCompanion(companionTasksOf({ free: purchases(4), mine: [{ item: craft(1), quantity: 1 }] }), { size: 'bar' })

    expect({
      both: [textOf(both, 'companion-count-free'), textOf(both, 'companion-count-mine')],
      purchasesOnly: [
        textOf(purchasesOnly, 'companion-count-free'),
        purchasesOnly.find('[data-testid="companion-count-free-build"]').exists(),
        textOf(purchasesOnly, 'companion-count-mine'),
        purchasesOnly.find('[data-testid="companion-count-mine-buy"]').exists(),
      ],
    }).toEqual({
      both: ['To assign: 58 to buy · 11 to build', 'To deliver: 1 to buy · 1 to build'],
      purchasesOnly: ['To assign: 4 to buy', false, 'To deliver: 1 to build', false],
    })
  })

  it('keeps a plain zero counter when there is nothing to assign or deliver', () => {
    const wrapper = mountCompanion(companionTasksOf(), { size: 'bar' })

    expect([textOf(wrapper, 'companion-count-free'), textOf(wrapper, 'companion-count-mine')]).toEqual(['0 to assign', '0 to deliver'])
  })

  it('translates the groups and the counters in French', () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [...purchases(2), ...crafts(1)] }), { locale: 'fr' })
    const bar = mountCompanion(companionTasksOf({ free: [...purchases(2), ...crafts(1)] }), { size: 'bar', locale: 'fr' })

    expect({
      groups: [headerOf(wrapper, 'companion-toggle-free-buy'), headerOf(wrapper, 'companion-toggle-free-build')],
      counter: textOf(bar, 'companion-count-free'),
    }).toEqual({ groups: [['À acheter', '2'], ['À fabriquer', '1']], counter: 'À attribuer : 2 à acheter · 1 à fabriquer' })
  })

  it('collapses a section or a sub-group on click, keeps its count visible and remembers it across mounts', async () => {
    const tasks = companionTasksOf({ free: [...purchases(2), ...crafts(3)], mine: [{ item: purchase(5), quantity: 2 }] })
    const wrapper = mountCompanion(tasks)

    await wrapper.get('[data-testid="companion-toggle-free-build"]').trigger('click')
    const afterSubGroup = {
      buy: wrapper.findAll(`[data-testid="companion-free-buy"] ${FREE_LINE}`).length,
      build: wrapper.findAll(`[data-testid="companion-free-build"] ${FREE_LINE}`).length,
      buildCount: headerOf(wrapper, 'companion-toggle-free-build'),
      expanded: wrapper.get('[data-testid="companion-toggle-free-build"]').attributes('aria-expanded'),
    }

    await wrapper.get('[data-testid="companion-toggle-mine"]').trigger('click')
    const afterSection = { mineLines: wrapper.findAll(MINE_LINE).length, mineCount: textOf(wrapper, 'companion-mine-total') }

    const remounted = mountCompanion(tasks)
    const afterRemount = {
      build: remounted.findAll(`[data-testid="companion-free-build"] ${FREE_LINE}`).length,
      mineLines: remounted.findAll(MINE_LINE).length,
      buy: remounted.findAll(`[data-testid="companion-free-buy"] ${FREE_LINE}`).length,
    }

    expect({ afterSubGroup, afterSection, afterRemount }).toEqual({
      afterSubGroup: { buy: 2, build: 0, buildCount: ['To build', '3'], expanded: 'false' },
      afterSection: { mineLines: 0, mineCount: '1' },
      afterRemount: { build: 0, mineLines: 0, buy: 2 },
    })
  })

  it('opens again on a second click and stores the collapsed keys', async () => {
    const wrapper = mountCompanion(companionTasksOf({ free: purchases(2) }))

    await wrapper.get('[data-testid="companion-toggle-free"]').trigger('click')
    const stored = localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY)
    await wrapper.get('[data-testid="companion-toggle-free"]').trigger('click')

    expect({
      stored,
      linesAfterReopen: wrapper.findAll(FREE_LINE).length,
      storedAfterReopen: localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY),
    }).toEqual({ stored: '["free"]', linesAfterReopen: 2, storedAfterReopen: '[]' })
  })

  it('stays open and keeps working when the browser refuses access to the storage', async () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })

    const wrapper = mountCompanion(companionTasksOf({ free: purchases(2) }))
    const openedByDefault = wrapper.findAll(FREE_LINE).length
    await wrapper.get('[data-testid="companion-toggle-free"]').trigger('click')

    getItem.mockRestore()
    setItem.mockRestore()
    expect({ openedByDefault, linesAfterClick: wrapper.findAll(FREE_LINE).length }).toEqual({ openedByDefault: 2, linesAfterClick: 0 })
  })

  it('ignores a stored value that is not a list of known sections', () => {
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, '{"free":true}')
    const garbage = mountCompanion(companionTasksOf({ free: purchases(2) }))
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, 'not json')
    const unparsable = mountCompanion(companionTasksOf({ free: purchases(2) }))
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, '["free:buy","unknown",42]')
    const partlyKnown = mountCompanion(companionTasksOf({ free: [...purchases(2), ...crafts(1)] }))

    expect({
      garbage: garbage.findAll(FREE_LINE).length,
      unparsable: unparsable.findAll(FREE_LINE).length,
      partlyKnown: partlyKnown.findAll(FREE_LINE).length,
    }).toEqual({ garbage: 2, unparsable: 2, partlyKnown: 1 })
  })
})
