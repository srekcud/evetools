import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionCarry, CompanionSupply, CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { bomLine, COMPANION_NOW, companionTasksOf } from './companionFixtures'

const NOW = Date.parse(COMPANION_NOW)
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000
const UNKNOWN_VALUE = '[data-unknown="true"]'
const FREE_LINE = '[data-testid="companion-free-line"]'
const MINE_LINE = '[data-testid="companion-mine-line"]'
const HERE_BADGE = '[data-testid="companion-here-badge"]'
const LINE_PLACE = '[data-testid="companion-line-place"]'
const CARRY = '[data-testid="companion-carry"]'
const SUPPLY = '[data-testid="companion-supply"]'
const ALERT = '[data-testid="companion-alert"]'

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

function supplyOf(id: string, typeId: number, typeName: string, quantity: number, here: boolean | null): CompanionSupply {
  return {
    item: bomLine({ id, typeId, typeName }),
    quantity,
    locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    systemName: 'Jita',
    here,
  }
}

function placeOf(systemName: string) {
  return { stationName: `${systemName} VII - Caldari Navy Assembly Plant`, systemName, here: null }
}

describe('GroupCompanion place and flow: here', () => {
  it.each(['column', 'bar'] as const)('writes the systems where my characters are in the %s header', (size) => {
    const wrapper = mountCompanion(companionTasksOf({ hereSystems: ['Jita', 'Perimeter'] }), { size })

    expect(wrapper.findAll('[data-testid="companion-here"]').map(here => here.text())).toEqual(['Here: Jita, Perimeter'])
  })

  it.each(['column', 'bar'] as const)('marks the position as unknown in the %s header and never invents a system', (size) => {
    const wrapper = mountCompanion(companionTasksOf({ hereSystems: null }), { size })

    expect(wrapper.findAll('[data-testid="companion-here"]').map(here => [here.attributes('data-unknown'), here.text()])).toEqual([['true', 'Here: unknown']])
  })

  it('shows the here badge and the place of a free line and of a reservation, and neither for a line that is not here or has no place', () => {
    const free = [
      bomLine({ id: 'line-here', typeId: 34, typeName: 'Tritanium', freeQuantity: 100 }),
      bomLine({ id: 'line-elsewhere', typeId: 35, typeName: 'Pyerite', freeQuantity: 200 }),
      bomLine({ id: 'line-no-place', typeId: 36, typeName: 'Mexallon', freeQuantity: 300 }),
    ]
    const mine = [
      { item: bomLine({ id: 'line-mine-here', typeId: 37, typeName: 'Isogen' }), quantity: 50 },
      { item: bomLine({ id: 'line-mine-unknown', typeId: 38, typeName: 'Nocxium' }), quantity: 60 },
    ]
    const linePlaces = {
      'line-here': { ...placeOf('Maurasi'), here: true },
      'line-elsewhere': { ...placeOf('Sobaseki'), here: false },
      'line-mine-here': { ...placeOf('Kisogo'), here: true },
      'line-mine-unknown': { ...placeOf('Hek'), here: null },
    }

    const wrapper = mountCompanion(companionTasksOf({ free, mine, linePlaces }))
    const shown = (lines: DOMWrapper<Element>[]) => lines.map(line => [line.find(HERE_BADGE).exists(), line.find(LINE_PLACE).exists()])

    expect({
      free: shown(wrapper.findAll(FREE_LINE)),
      mine: shown(wrapper.findAll(MINE_LINE)),
      placeSystems: wrapper.findAll(LINE_PLACE).map(place => ['Maurasi', 'Sobaseki', 'Kisogo', 'Hek'].filter(system => place.text().includes(system))),
    }).toEqual({
      free: [[true, true], [false, true], [false, false]],
      mine: [[true, true], [false, true]],
      placeSystems: [['Maurasi'], ['Sobaseki'], ['Kisogo'], ['Hek']],
    })
  })
})

describe('GroupCompanion place and flow: carries and supplies', () => {
  it('lists each carry with its route, its first type and the number of other types, and marks an unknown end or volume', () => {
    const carries = [
      carryOf({ id: 'transfer-known', lines: [{ typeName: 'Morphite', quantity: 300 }, { typeName: 'Nocxium', quantity: 60 }], volume: 900 }),
      carryOf({ id: 'transfer-unknown-start', from: null, fromSystem: null, state: 'awaiting_departure', here: null }),
      carryOf({ id: 'transfer-unknown-volume', volume: null, lines: [{ typeName: 'Zydrine', quantity: 45 }] }),
    ]

    const wrapper = mountCompanion(companionTasksOf({ carries }))
    const summaries = wrapper.findAll(CARRY).map((carry) => {
      const route = carry.find('[data-testid="companion-carry-route"]')
      const routeText = route.exists() ? route.text() : ''
      return {
        hasRoute: route.exists(),
        hasArrow: routeText.includes('→'),
        hasStart: routeText.includes('Jita IV'),
        hasEnd: routeText.includes('Perimeter - Tranquility Trading Tower'),
        routeUnknown: route.find(UNKNOWN_VALUE).exists(),
        markers: carry.findAll(UNKNOWN_VALUE).length,
        firstType: carry.text().includes('Morphite'),
        otherTypes: carry.text().includes('+1'),
        volume: carry.text().includes('900'),
      }
    })

    expect(summaries).toEqual([
      { hasRoute: true, hasArrow: true, hasStart: true, hasEnd: true, routeUnknown: false, markers: 0, firstType: true, otherTypes: true, volume: true },
      { hasRoute: true, hasArrow: true, hasStart: false, hasEnd: true, routeUnknown: true, markers: 1, firstType: true, otherTypes: false, volume: true },
      { hasRoute: true, hasArrow: true, hasStart: true, hasEnd: true, routeUnknown: false, markers: 1, firstType: false, otherTypes: false, volume: false },
    ])
  })

  it('shows the time left before the expected arrival of a carry in transit only', () => {
    const carries = [
      carryOf({ id: 'transfer-moving', state: 'in_transit', expectedArrivalAt: '2026-11-21T14:00:00+00:00' }),
      carryOf({ id: 'transfer-waiting', state: 'awaiting_departure', expectedArrivalAt: '2026-11-21T14:00:00+00:00' }),
    ]

    const wrapper = mountCompanion(companionTasksOf({ carries }))

    expect(wrapper.findAll(CARRY).map(carry => carry.text().includes('02:00'))).toEqual([true, false])
  })

  it('marks the carries unknown instead of saying nothing is to deliver when the transfers could not be read', () => {
    const unreadable = mountCompanion(companionTasksOf({ carriesUnknown: true }))
    const known = mountCompanion(companionTasksOf())

    expect({
      unreadable: unreadable.findAll('[data-testid="companion-carries-unknown"]').map(line => line.attributes('data-unknown')),
      emptyWhenUnreadable: unreadable.get('[data-testid="companion-pane-mine"]').text().includes(en.groupIndustry.companion.emptyMine),
      known: known.findAll('[data-testid="companion-carries-unknown"]').length,
      emptyWhenKnown: known.get('[data-testid="companion-pane-mine"]').text().includes(en.groupIndustry.companion.emptyMine),
    }).toEqual({ unreadable: ['true'], emptyWhenUnreadable: false, known: 0, emptyWhenKnown: true })
  })

  it('offers the arrival button only for a carry in transit and emits the carry id', async () => {
    const carries = [
      carryOf({ id: 'transfer-waiting', state: 'awaiting_departure' }),
      carryOf({ id: 'transfer-moving', state: 'in_transit' }),
      carryOf({ id: 'transfer-unknown-state', state: 'unknown' }),
    ]
    const wrapper = mountCompanion(companionTasksOf({ carries }))

    const buttonsByCarry = wrapper.findAll(CARRY).map(carry => carry.findAll('[data-testid="companion-carry-arrive"]').length)
    await Promise.all(wrapper.findAll('[data-testid="companion-carry-arrive"]').map(button => button.trigger('click')))

    expect({ buttonsByCarry, arrive: wrapper.emitted('arrive') }).toEqual({ buttonsByCarry: [0, 1, 0], arrive: [['transfer-moving']] })
  })

  it('lists each supply with its quantity, a here badge only where it is here, and emits the line id when applied', async () => {
    const supplies = [
      supplyOf('bom-morphite', 11_399, 'Morphite', 300, true),
      supplyOf('bom-nocxium', 38, 'Nocxium', 60, false),
      supplyOf('bom-zydrine', 39, 'Zydrine', 45, null),
    ]
    const wrapper = mountCompanion(companionTasksOf({ supplies }))
    const rows = wrapper.findAll(SUPPLY)

    await Promise.all(rows.map(row => row.findAll('[data-testid="companion-supply-apply"]'))
      .flatMap((buttons, index) => (index === 1 ? buttons.map(button => button.trigger('click')) : [])))

    expect({
      names: rows.map(row => ['Morphite', 'Nocxium', 'Zydrine'].filter(name => row.text().includes(name))),
      quantities: rows.map((row, index) => row.text().includes(['300', '60', '45'][index])),
      badges: rows.map(row => row.find(HERE_BADGE).exists()),
      applyButtons: rows.map(row => row.findAll('[data-testid="companion-supply-apply"]').length),
      supply: wrapper.emitted('supply'),
    }).toEqual({
      names: [['Morphite'], ['Nocxium'], ['Zydrine']],
      quantities: [true, true, true],
      applyButtons: [1, 1, 1],
      badges: [true, false, false],
      supply: [['bom-nocxium']],
    })
  })

  it('puts the carries then the supplies above the activity groups of the reservations to deliver', () => {
    const mine = [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 50 }]
    const wrapper = mountCompanion(companionTasksOf({
      mine,
      carries: [carryOf()],
      supplies: [supplyOf('bom-morphite', 11_399, 'Morphite', 300, true)],
    }))
    const html = wrapper.get('[data-testid="companion-pane-mine"]').html()
    const position = (testId: string) => html.indexOf(`data-testid="${testId}"`)

    expect(position('companion-carries') >= 0 && position('companion-carries') < position('companion-supply') && position('companion-supply') < position('companion-group-mine-material')).toBe(true)
  })
})

describe('GroupCompanion place and flow: reservation expiry', () => {
  it('writes the time left of each reservation to deliver, in hours and minutes under a day and in days beyond, and says expired once elapsed', () => {
    const mine = [
      { item: bomLine({ id: 'line-far', typeId: 34, typeName: 'Tritanium' }), quantity: 50 },
      { item: bomLine({ id: 'line-near', typeId: 35, typeName: 'Pyerite' }), quantity: 60 },
      { item: bomLine({ id: 'line-elapsed', typeId: 36, typeName: 'Mexallon' }), quantity: 70 },
      { item: bomLine({ id: 'line-unreadable', typeId: 37, typeName: 'Isogen' }), quantity: 80 },
    ]
    const reservationExpiries = {
      'line-far': NOW + 41 * ONE_HOUR,
      'line-near': NOW + 5 * ONE_HOUR + 7 * ONE_MINUTE,
      'line-elapsed': NOW - ONE_MINUTE,
    }

    const wrapper = mountCompanion(companionTasksOf({ mine, reservationExpiries }), { locale: 'fr' })
    const expiryOf = (line: DOMWrapper<Element>) => {
      const expiry = line.find('[data-testid="companion-reservation-expiry"]')
      return expiry.exists() ? expiry.text() : null
    }
    const expiries = wrapper.findAll(MINE_LINE).map(expiryOf)

    expect({
      count: expiries.length,
      far: expiries[0]?.includes('1 j 17 h'),
      near: expiries[1]?.includes('05:07'),
      elapsed: expiries[2]?.includes('expirée'),
      unreadable: expiries[3],
    }).toEqual({ count: 4, far: true, near: true, elapsed: true, unreadable: null })
  })
})

describe('GroupCompanion place and flow: join requests', () => {
  const joinRequestAlert = [{ key: 'join_request', kind: 'join_request' as const }]

  it('names the only requester and opens the members from the alert button', async () => {
    const wrapper = mountCompanion(companionTasksOf({ alerts: joinRequestAlert, joinRequests: 1, joinRequestNames: ['Kaelen'] }))

    await Promise.all(wrapper.findAll('[data-testid="companion-alert-action"]').map(button => button.trigger('click')))

    expect({ text: wrapper.findAll(ALERT).map(alert => alert.text().includes('Kaelen')), openMembers: wrapper.emitted('open-members')?.length }).toEqual({ text: [true], openMembers: 1 })
  })

  it('counts the requests when there are several or when the only requester has no known name', () => {
    const several = mountCompanion(companionTasksOf({ alerts: joinRequestAlert, joinRequests: 2, joinRequestNames: ['Kaelen'] }))
    const unnamed = mountCompanion(companionTasksOf({ alerts: joinRequestAlert, joinRequests: 1, joinRequestNames: [] }))

    expect({
      several: several.findAll(ALERT).map(alert => [alert.text().includes('Kaelen'), alert.text().includes('2')]),
      unnamed: unnamed.findAll(ALERT).map(alert => [alert.text().includes('Kaelen'), alert.text().includes('1')]),
    }).toEqual({ several: [[false, true]], unnamed: [[false, true]] })
  })
})

describe('GroupCompanion place and flow: to deliver tab', () => {
  it('counts the reservations, the carries and the supplies in the to deliver tab and the bar, and makes the tab hot', () => {
    const tasks = companionTasksOf({
      mine: [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 50 }],
      carries: [carryOf({ id: 'transfer-a' }), carryOf({ id: 'transfer-b' })],
      supplies: [supplyOf('bom-morphite', 11_399, 'Morphite', 300, true)],
    })
    const idle = companionTasksOf()

    const column = mountCompanion(tasks)
    const bar = mountCompanion(tasks, { size: 'bar' })
    const idleColumn = mountCompanion(idle)

    expect({
      tab: column.get('[data-testid="companion-tab-mine"]').text().includes('4'),
      hot: column.get('[data-testid="companion-tab-mine"]').attributes('data-hot'),
      bar: bar.get('[data-testid="companion-count-mine"]').text().includes('4'),
      idleHot: idleColumn.get('[data-testid="companion-tab-mine"]').attributes('data-hot'),
    }).toEqual({ tab: true, hot: 'true', bar: true, idleHot: 'false' })
  })

  it('keeps the count of the reservations alone when there is no flow data', () => {
    const tasks = companionTasksOf({ mine: [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 50 }] })

    const column = mountCompanion(tasks)

    expect(column.get('[data-testid="companion-tab-mine"]').text()).toMatch(/1$/)
  })
})
