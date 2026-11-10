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
const ALERT = '[data-testid="companion-alert"]'
const STANDALONE_ZERO = /(^|\D)0(\D|$)/

function mountCompanion(tasks: CompanionTasks, options: { size?: 'bar' | 'column'; locale?: 'en' | 'fr' } = {}): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: options.size ?? 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })] },
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

  it('shows the move products alert in the column and as the bar pill unless a job ready alert comes first', () => {
    const moveProducts = { quantity: 10, jobCount: 1, unknownJobCount: 0 }
    const moveOnly = companionTasksOf({ alerts: [{ key: 'move_products', kind: 'move_products' }], moveProducts })
    const withJobReady = companionTasksOf({
      alerts: [{ key: 'move_products', kind: 'move_products' }, { key: 'job_ready:match-ferox', kind: 'job_ready' }],
      moveProducts,
    })

    const column = mountCompanion(moveOnly, { locale: 'fr' })
    const bar = mountCompanion(moveOnly, { size: 'bar', locale: 'fr' })
    const barWithJobReady = mountCompanion(withJobReady, { size: 'bar', locale: 'fr' })

    expect({
      column: column.findAll(ALERT).map(alert => alert.text()),
      bar: bar.findAll(ALERT).map(alert => ({ kind: alert.attributes('data-kind'), text: alert.text() })),
      barWithJobReady: barWithJobReady.findAll(ALERT).map(alert => alert.attributes('data-kind')),
    }).toEqual({
      column: ['Déplacer 10 produits dans le container du projet (1 jobs)'],
      bar: [{ kind: 'move_products', text: 'Déplacer 10 produits dans le container du projet (1 jobs)' }],
      barWithJobReady: ['job_ready'],
    })
  })

  it('shows the price alert in the column and as the bar pill, below a job ready alert, without sound', () => {
    const priceAlert = { typeId: 11_993, typeName: 'Hammerhead II', quantity: 6, jitaUnitPrice: 690_000, breakEvenPerUnit: 760_000, gapPerUnit: 70_000 }
    const priceOnly = companionTasksOf({ alerts: [{ key: 'price_below_break_even', kind: 'price_below_break_even' }], priceAlert })
    const withJobReady = companionTasksOf({
      alerts: [{ key: 'price_below_break_even', kind: 'price_below_break_even' }, { key: 'job_ready:match-ferox', kind: 'job_ready' }],
      priceAlert,
    })

    const column = mountCompanion(priceOnly)
    const bar = mountCompanion(priceOnly, { size: 'bar' })
    const barWithJobReady = mountCompanion(withJobReady, { size: 'bar' })
    const normalize = (text: string): string => text.replace(/[\u00a0\u202f]/g, ' ')

    expect({
      column: column.findAll(ALERT).map(alert => normalize(alert.text())),
      bar: bar.findAll(ALERT).map(alert => alert.attributes('data-kind')),
      barWithJobReady: barWithJobReady.findAll(ALERT).map(alert => alert.attributes('data-kind')),
      audio: column.findAll('audio').length,
    }).toEqual({
      column: ['Jita 690.00 K < break-even 760.00 K · do not sell on the market for now'],
      bar: ['price_below_break_even'],
      barWithJobReady: ['job_ready'],
      audio: 0,
    })
  })

  it('shows a discreet unknown output location line in the column only when jobs are unknown, never in the bar', () => {
    const unknown = companionTasksOf({ moveProducts: { quantity: 0, jobCount: 0, unknownJobCount: 2 } })
    const none = companionTasksOf()

    const column = mountCompanion(unknown, { locale: 'fr' })
    const bar = mountCompanion(unknown, { size: 'bar', locale: 'fr' })
    const columnWithoutUnknown = mountCompanion(none, { locale: 'fr' })

    expect({
      column: column.findAll(UNKNOWN_VALUE).map(line => line.text()),
      alertsInColumn: column.findAll(ALERT).length,
      bar: bar.findAll(UNKNOWN_VALUE).length,
      columnWithoutUnknown: columnWithoutUnknown.findAll(UNKNOWN_VALUE).length,
    }).toEqual({
      column: ['Lieu de sortie inconnu pour 2 jobs : à vérifier dans la BOM'],
      alertsInColumn: 0,
      bar: 0,
      columnWithoutUnknown: 0,
    })
  })
})
