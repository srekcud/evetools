import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionCarry, CompanionLeg, CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, COMPANION_NOW, companionLegOf, companionTasksOf, transportLegLine } from './companionFixtures'

const NOW = Date.parse(COMPANION_NOW)
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000
const CUBIC_METERS = '³'
const UNKNOWN_VALUE = '[data-unknown="true"]'
const LEG = '[data-testid="companion-leg"]'
const LEG_LINE = '[data-testid="companion-leg-line"]'
const LEG_DECLARE = '[data-testid="companion-leg-declare"]'
const HERE_BADGE = '[data-testid="companion-here-badge"]'
const TRANSPORT_PANE = '[data-testid="companion-pane-transport"]'
const MINE_PANE = '[data-testid="companion-pane-mine"]'
const AMARR_TO_PERIMETER = 'amarr>perimeter'
const AMARR_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'

function mountCompanion(tasks: CompanionTasks, options: { size?: 'bar' | 'column'; locale?: 'en' | 'fr' } = {}): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: options.size ?? 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })] },
  })
}

function carryOf(overrides: Partial<CompanionCarry> = {}): CompanionCarry {
  return {
    id: 'transfer-morphite',
    state: 'in_transit',
    from: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    to: 'Perimeter - Tranquility Trading Tower',
    fromSystem: 'Jita',
    toSystem: 'Perimeter',
    volume: 900,
    lines: [{ typeName: 'Morphite', quantity: 300 }],
    expectedArrivalAt: '2026-11-21T14:00:00+00:00',
    here: true,
    ...overrides,
  }
}

function amarrLeg(overrides: Partial<CompanionLeg> = {}): CompanionLeg {
  return companionLegOf({
    key: AMARR_TO_PERIMETER,
    from: AMARR_STATION_NAME,
    fromSystem: 'Amarr',
    volume: 450,
    lines: [transportLegLine({ typeId: 35, typeName: 'Pyerite', quantity: 75, unitVolume: 6, volume: 450 })],
    ...overrides,
  })
}

function hubLeg(overrides: Partial<CompanionLeg> = {}): CompanionLeg {
  return companionLegOf({
    key: 's30000142>perimeter',
    from: 'Jita',
    fromSystem: 'Jita',
    here: null,
    volume: 1,
    lines: [transportLegLine({ typeId: 39, typeName: 'Zydrine', quantity: 100, source: 'purchase', unitVolume: 0.01, volume: 1 })],
    ...overrides,
  })
}

function textOf(element: DOMWrapper<Element>): string {
  return element.text().replace(/\s+/g, ' ')
}

function includesAll(element: DOMWrapper<Element>, parts: string[]): boolean {
  return parts.every(part => textOf(element).includes(part))
}

describe('GroupCompanion transport tab: legs (R6)', () => {
  it('writes each leg with its route, its volume and its lines, and the here badge only on the leg leaving from where I am', () => {
    const tasks = companionTasksOf({ legs: [companionLegOf({ here: true }), amarrLeg({ here: false }), hubLeg()], readyLegCount: 3 })

    const legs = mountCompanion(tasks).findAll(`${TRANSPORT_PANE} ${LEG}`)

    expect({
      count: legs.length,
      here: legs.slice(0, 2).map(leg => leg.attributes('data-here')),
      access: legs.map(leg => leg.attributes('data-access')),
      badges: legs.map(leg => leg.find(HERE_BADGE).exists()),
      first: legs.slice(0, 1).map(leg => includesAll(leg, ['Jita IV - Moon 4', '→', 'Perimeter - Tranquility Trading Tower', 'Morphite', '40', `400 m${CUBIC_METERS}`])),
      second: legs.slice(1, 2).map(leg => includesAll(leg, [AMARR_STATION_NAME, '→', 'Pyerite', '75', `450 m${CUBIC_METERS}`])),
      hub: legs.slice(2).map(leg => [includesAll(leg, ['Jita', 'Zydrine', '100', `1 m${CUBIC_METERS}`]), textOf(leg).includes('Caldari Navy')]),
    }).toEqual({
      count: 3,
      here: ['true', 'false'],
      access: ['granted', 'granted', 'granted'],
      badges: [true, false, false],
      first: [true],
      second: [true],
      hub: [[true, false]],
    })
  })

  it('marks an unknown volume or quantity as unknown, never as zero, and counts down to the end of a job in production', () => {
    const lines = [
      transportLegLine({ typeId: 38, typeName: 'Nocxium', quantity: 5, unitVolume: null, volume: null }),
      transportLegLine({ typeId: 39, typeName: 'Zydrine', quantity: null, state: 'in_transit', source: 'transfer', unitVolume: 0.01, volume: null, unknownReason: 'transfer_unreadable' }),
      transportLegLine({ typeId: 35, typeName: 'Pyerite', quantity: 60, state: 'in_production', readyAt: new Date(NOW + 2 * ONE_HOUR).toISOString(), unitVolume: 6, volume: 360 }),
      transportLegLine({ typeId: 36, typeName: 'Mexallon', quantity: 80, state: 'in_production', readyAt: null, unitVolume: 2, volume: 160 }),
      transportLegLine({ typeId: 37, typeName: 'Isogen', quantity: 90, state: 'in_production', readyAt: new Date(NOW - ONE_MINUTE).toISOString(), unitVolume: 5, volume: 450 }),
    ]

    const legViews = mountCompanion(companionTasksOf({ legs: [companionLegOf({ volume: null, lines })], readyLegCount: 1 }), { locale: 'fr' }).findAll(`${TRANSPORT_PANE} ${LEG}`)
    const lineViews = legViews.flatMap(leg => leg.findAll(LEG_LINE))

    expect({
      states: lineViews.map(line => line.attributes('data-state')),
      markers: lineViews.map(line => line.findAll(UNKNOWN_VALUE).length),
      countdown: lineViews.slice(2).map(line => textOf(line).includes('02:00')),
      ready: lineViews.slice(2).map(line => textOf(line).includes('prêt')),
      legMarkers: legViews.map(leg => leg.findAll(UNKNOWN_VALUE).length),
      zeroVolume: legViews.map(leg => new RegExp(`(^|\\s)0 m${CUBIC_METERS}`).test(textOf(leg))),
    }).toEqual({
      states: ['ready', 'in_transit', 'in_production', 'in_production', 'in_production'],
      markers: [1, 2, 0, 1, 0],
      countdown: [true, false, false],
      ready: [false, false, true],
      legMarkers: [5],
      zeroVolume: [false],
    })
  })
})

describe('GroupCompanion transport tab: actions and readings (R7, R8)', () => {
  it('offers the declare button on a leg that can be declared and emits its key, writes a declared leg without a button and says when the access is unknown', async () => {
    const tasks = companionTasksOf({
      legs: [
        companionLegOf({ key: 'leg-open' }),
        amarrLeg({ key: 'leg-unknown-access', accessUnknown: true }),
        companionLegOf({ key: 'leg-declared', declared: true, canDeclare: false, declaration: null }),
        hubLeg({ key: 'leg-blocked', canDeclare: false, declaration: null }),
      ],
      readyLegCount: 3,
    })
    const wrapper = mountCompanion(tasks)
    const legs = wrapper.findAll(`${TRANSPORT_PANE} ${LEG}`)

    await Promise.all(legs.flatMap(leg => leg.findAll(LEG_DECLARE)).map(button => button.trigger('click')))

    expect({
      buttons: legs.map(leg => leg.findAll(LEG_DECLARE).length),
      declaredBadges: legs.map(leg => leg.findAll('[data-testid="companion-leg-declared"]').length),
      accessMention: legs.map(leg => leg.findAll('[data-testid="companion-leg-access-unknown"]').length),
      access: legs.map(leg => leg.attributes('data-access')),
      emitted: wrapper.emitted('declare-leg'),
    }).toEqual({
      buttons: [1, 1, 0, 0],
      declaredBadges: [0, 0, 1, 0],
      accessMention: [0, 1, 0, 0],
      access: ['granted', 'unknown', 'granted', 'granted'],
      emitted: [['leg-open'], ['leg-unknown-access']],
    })
  })

  it('says the legs are unknown when they could not be read, says there is no leg only after a successful read and counts the unreadable readings', () => {
    const unknown = mountCompanion(companionTasksOf({ legsUnknown: true }))
    const empty = mountCompanion(companionTasksOf())
    const partial = mountCompanion(companionTasksOf({ legs: [companionLegOf()], readyLegCount: 1, legsUnreadableCount: 2 }))
    const linesOf = (wrapper: VueWrapper, testId: string) => wrapper.findAll(`${TRANSPORT_PANE} [data-testid="${testId}"]`)

    expect({
      unknown: [linesOf(unknown, 'companion-legs-unknown').map(line => line.attributes('data-unknown')), linesOf(unknown, 'companion-legs-empty').length, linesOf(unknown, 'companion-leg').length],
      empty: [linesOf(empty, 'companion-legs-unknown').length, linesOf(empty, 'companion-legs-empty').length, linesOf(empty, 'companion-legs-unreadable').length],
      partial: [linesOf(partial, 'companion-legs-empty').length, linesOf(partial, 'companion-legs-unreadable').map(line => line.text().includes('2')), linesOf(partial, 'companion-leg').length],
    }).toEqual({
      unknown: [['true'], 0, 0],
      empty: [0, 1, 0],
      partial: [0, [true], 1],
    })
  })
})

describe('GroupCompanion transport tab: tab and carries (R5, R7)', () => {
  it('adds the transport tab between to deliver and my jobs, counts the legs still to declare and makes the tab hot, on four columns', () => {
    const busy = mountCompanion(companionTasksOf({ legs: [companionLegOf(), amarrLeg()], readyLegCount: 2 }))
    const idle = mountCompanion(companionTasksOf({ legs: [companionLegOf({ declared: true, canDeclare: false, declaration: null })], readyLegCount: 0 }))

    const transportTab = (wrapper: VueWrapper) => wrapper.findAll('[data-testid="companion-tab-transport"]')

    expect({
      tabs: busy.findAll('[role="tab"]').map(tab => tab.attributes('data-testid')),
      columns: busy.get('[role="tablist"]').classes().includes('grid-cols-4'),
      busy: transportTab(busy).map(tab => [tab.text().endsWith('2'), tab.attributes('data-hot')]),
      idle: transportTab(idle).map(tab => [tab.text().endsWith('0'), tab.attributes('data-hot')]),
    }).toEqual({
      tabs: ['companion-tab-free', 'companion-tab-mine', 'companion-tab-transport', 'companion-tab-jobs'],
      columns: true,
      busy: [[true, 'true']],
      idle: [[true, 'false']],
    })
  })

  it('moves the carries and their unknown message from the to deliver tab to the transport tab, below the legs, and leaves them out of the to deliver count', () => {
    const mine = [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 50 }]
    const supplies = [{ item: bomLine({ id: 'bom-morphite', typeId: 11_399, typeName: 'Morphite' }), quantity: 300, locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', here: true }]
    const withCarries = mountCompanion(companionTasksOf({ legs: [companionLegOf()], readyLegCount: 1, mine, supplies, carries: [carryOf({ id: 'transfer-a' }), carryOf({ id: 'transfer-b' })] }))
    const unreadable = mountCompanion(companionTasksOf({ carriesUnknown: true }))
    const transportHtml = withCarries.findAll(TRANSPORT_PANE).map(pane => pane.html()).join('')

    expect({
      carriesInTransport: withCarries.findAll(`${TRANSPORT_PANE} [data-testid="companion-carry"]`).length,
      carriesInMine: withCarries.get(MINE_PANE).findAll('[data-testid="companion-carry"]').length,
      belowLegs: transportHtml.indexOf('data-testid="companion-leg"') >= 0 && transportHtml.indexOf('data-testid="companion-leg"') < transportHtml.indexOf('data-testid="companion-carries"'),
      toDeliverTab: withCarries.get('[data-testid="companion-tab-mine"]').text().endsWith('2'),
      unknownInTransport: unreadable.findAll(`${TRANSPORT_PANE} [data-testid="companion-carries-unknown"]`).map(line => line.attributes('data-unknown')),
      unknownInMine: unreadable.get(MINE_PANE).findAll('[data-testid="companion-carries-unknown"]').length,
      emptyMine: unreadable.get(MINE_PANE).text().includes(en.groupIndustry.companion.emptyMine),
    }).toEqual({
      carriesInTransport: 2,
      carriesInMine: 0,
      belowLegs: true,
      toDeliverTab: true,
      unknownInTransport: ['true'],
      unknownInMine: 0,
      emptyMine: true,
    })
  })
})
