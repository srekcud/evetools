import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, companionTasksOf } from './companionFixtures'

const FREE_LINE = '[data-testid="companion-free-line"]'
const MORE_FREE = '[data-testid="companion-more-free"]'
const TODO_SECTION = '[data-testid="companion-todo"]'
const UNKNOWN_VALUE = '[data-unknown="true"]'
const STANDALONE_ZERO = /(^|\D)0(\D|$)/

function mountCompanion(tasks: CompanionTasks): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

async function clickAll(wrapper: VueWrapper, testId: string): Promise<void> {
  await Promise.all(wrapper.findAll(`[data-testid="${testId}"]`).map(element => element.trigger('click')))
}

describe('GroupCompanion', () => {
  it('shows six free lines then the number of remaining free lines', () => {
    const free = Array.from({ length: 9 }, (_, index) => bomLine({
      id: `bom-free-${index}`,
      typeId: 1_000 + index,
      typeName: `Mineral ${index}`,
      freeQuantity: 10 * (index + 1),
      estimatedPrice: 2 + index,
    }))

    const wrapper = mountCompanion(companionTasksOf({ free }))
    const more = wrapper.find(MORE_FREE)

    expect({
      lines: wrapper.findAll(FREE_LINE).length,
      remaining: more.exists() ? more.attributes('data-count') : undefined,
    }).toEqual({ lines: 6, remaining: '3' })
  })

  it('marks the value of a free material without price as unknown and never shows a zero for it', () => {
    const free = [
      bomLine({ id: 'bom-nocxium', typeId: 38, typeName: 'Nocxium', freeQuantity: 80, estimatedPrice: null, estimatedTotal: null }),
      bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', freeQuantity: 1_000, estimatedPrice: 5.5 }),
    ]

    const lines = mountCompanion(companionTasksOf({ free })).findAll(FREE_LINE)

    expect({
      unknownMarkers: lines.map(line => line.find(UNKNOWN_VALUE).exists()),
      zeroOnUnknownLine: lines.slice(0, 1).map(line => STANDALONE_ZERO.test(line.text())),
    }).toEqual({ unknownMarkers: [true, false], zeroOnUnknownLine: [false] })
  })

  it('shows the to do section when contributions or join requests are known and hides it when they are not', () => {
    const admin = mountCompanion(companionTasksOf({ toReview: 3, joinRequests: 2 }))
    const plainMember = mountCompanion(companionTasksOf({ toReview: null, joinRequests: null }))

    expect({ admin: admin.find(TODO_SECTION).exists(), plainMember: plainMember.find(TODO_SECTION).exists() })
      .toEqual({ admin: true, plainMember: false })
  })

  it('emits the line id when claiming a free line, delivering or releasing one of mine', async () => {
    const wrapper = mountCompanion(companionTasksOf({
      free: [bomLine({ id: 'bom-nocxium', typeId: 38, typeName: 'Nocxium', freeQuantity: 80 })],
      mine: [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen', freeQuantity: 0 }), quantity: 50 }],
    }))

    await clickAll(wrapper, 'companion-claim')
    await clickAll(wrapper, 'companion-deliver')
    await clickAll(wrapper, 'companion-release')

    expect({
      claim: wrapper.emitted('claim'),
      deliver: wrapper.emitted('deliver'),
      release: wrapper.emitted('release'),
    }).toEqual({
      claim: [['bom-nocxium']],
      deliver: [['bom-isogen']],
      release: [['bom-isogen']],
    })
  })
})
