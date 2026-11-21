import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionTasksOf } from './companionFixtures'

const FREE_LINE = '[data-testid="companion-free-line"]'
const MINE_LINE = '[data-testid="companion-mine-line"]'
const WAITING = '[data-testid="companion-waiting"]'
const MORE_FREE_BUILD = '[data-testid="companion-more-free"]'

function buildLine(id: string, typeName: string) {
  return bomLine({ id, typeId: 12_000 + id.length, typeName, isJob: true, jobGroup: 'component', activityType: 'manufacturing', runs: 2, freeQuantity: 2 })
}

function mountCompanion(tasks: CompanionTasks, locale: 'en' | 'fr' = 'en') {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('GroupCompanion craft line order and waiting tag', () => {
  it('tags only the blocked build lines of the free and mine sections as waiting for components', () => {
    const ishtar = buildLine('bom-ishtar', 'Ishtar')
    const hull = buildLine('bom-hull', 'Hull')

    const wrapper = mountCompanion(companionTasksOf({
      free: [hull, ishtar],
      mine: [{ item: hull, quantity: 1 }, { item: ishtar, quantity: 1 }],
      waitingForComponents: ['bom-ishtar'],
    }))

    expect({
      free: wrapper.findAll(FREE_LINE).map(line => [line.text().includes('Ishtar'), line.find(WAITING).exists()]),
      mine: wrapper.findAll(MINE_LINE).map(line => [line.text().includes('Ishtar'), line.find(WAITING).exists()]),
      text: wrapper.find(WAITING).text(),
    }).toEqual({
      free: [[false, false], [true, true]],
      mine: [[false, false], [true, true]],
      text: 'Waiting for components',
    })
  })

  it('shows the six first build lines of the chain order and hides the last ones behind more', () => {
    const free = Array.from({ length: 8 }, (_, index) => buildLine(`bom-job-${index}`, `Component ${index}`))

    const wrapper = mountCompanion(companionTasksOf({ free }))

    expect({
      shown: wrapper.findAll(FREE_LINE).map(line => line.find('span').text()),
      hidden: wrapper.find(MORE_FREE_BUILD).attributes('data-count'),
    }).toEqual({
      shown: ['Component 0', 'Component 1', 'Component 2', 'Component 3', 'Component 4', 'Component 5'],
      hidden: '2',
    })
  })

  it('translates the tag in French', () => {
    const ishtar = buildLine('bom-ishtar', 'Ishtar')

    const wrapper = mountCompanion(companionTasksOf({ free: [ishtar], waitingForComponents: ['bom-ishtar'] }), 'fr')

    expect(wrapper.find(WAITING).text()).toBe('En attente des composants')
  })
})
