import { formatEft, groupAssembledShips, isBadFitName, isFittedFlag, isGroupableShip, shipFitOf } from '@/utils/shipFit'
import type { Asset } from '@/types'

const SHIP_CATEGORY_ID = 6
const CHARGE_CATEGORY_ID = 8
const RIFTER_TYPE_ID = 587

let nextItemId = 1000

function asset(overrides: Partial<Asset>): Asset {
  nextItemId += 1
  return {
    id: `00000000-0000-4000-8000-${String(nextItemId).padStart(12, '0')}`,
    itemId: nextItemId,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 1,
    locationId: 60003760,
    locationName: 'Jita IV',
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

function ship(name: string | null, overrides: Partial<Asset> = {}): Asset {
  return asset({
    typeId: RIFTER_TYPE_ID,
    typeName: 'Rifter',
    categoryId: SHIP_CATEGORY_ID,
    isSingleton: true,
    itemName: name,
    ...overrides,
  })
}

function moduleIn(parent: Asset, flag: string, typeId: number, typeName: string | null, overrides: Partial<Asset> = {}): Asset {
  return asset({
    typeId,
    typeName,
    categoryId: 7,
    locationId: parent.itemId,
    locationType: 'item',
    locationFlag: flag,
    ...overrides,
  })
}

function contentsMap(contents: Asset[]): (itemId: number) => Asset[] {
  return itemId => contents.filter(content => content.locationId === itemId)
}

describe('isFittedFlag', () => {
  it.each(['LoSlot0', 'LoSlot7', 'MedSlot3', 'HiSlot0', 'RigSlot2', 'SubSystemSlot4'])('treats %s as a fitted slot', flag => {
    expect(isFittedFlag(flag)).toBe(true)
  })

  it.each(['Cargo', 'DroneBay', 'Hangar', 'ShipHangar', null])('does not treat %s as a fitted slot', flag => {
    expect(isFittedFlag(flag)).toBe(false)
  })
})

describe('isGroupableShip', () => {
  it('accepts an assembled ship', () => {
    expect(isGroupableShip(ship('Alpha'))).toBe(true)
  })

  it('accepts an assembled ship that has no custom name and nothing to resolve', () => {
    expect(isGroupableShip(ship(null))).toBe(true)
  })

  it('rejects a stack of unassembled ships', () => {
    expect(isGroupableShip(ship(null, { isSingleton: false, quantity: 5 }))).toBe(false)
  })

  it('rejects an assembled ship whose name still has to be resolved', () => {
    expect(isGroupableShip(ship(null, { canResolveItemName: true }))).toBe(false)
  })

  it('rejects a non-ship item', () => {
    expect(isGroupableShip(asset({ isSingleton: true }))).toBe(false)
  })
})

describe('shipFitOf', () => {
  it('sorts each slot kind by slot index and ignores loaded charges', () => {
    const parent = ship('Alpha')
    const fit = shipFitOf([
      moduleIn(parent, 'HiSlot1', 100, 'Autocannon B'),
      moduleIn(parent, 'HiSlot0', 101, 'Autocannon A'),
      moduleIn(parent, 'HiSlot0', 900, 'EMP S', { categoryId: CHARGE_CATEGORY_ID, quantity: 40 }),
      moduleIn(parent, 'MedSlot0', 102, 'Microwarpdrive'),
      moduleIn(parent, 'LoSlot0', 103, 'Damage Control'),
      moduleIn(parent, 'RigSlot0', 104, 'Core Defense Field Extender'),
      moduleIn(parent, 'SubSystemSlot0', 105, 'Defensive Subsystem'),
    ])

    expect(fit.high.map(module => module.typeName)).toEqual(['Autocannon A', 'Autocannon B'])
    expect(fit.mid.map(module => module.typeName)).toEqual(['Microwarpdrive'])
    expect(fit.low.map(module => module.typeName)).toEqual(['Damage Control'])
    expect(fit.rig.map(module => module.typeName)).toEqual(['Core Defense Field Extender'])
    expect(fit.subsystem.map(module => module.typeName)).toEqual(['Defensive Subsystem'])
  })

  it('adds up drone and cargo stacks by type', () => {
    const parent = ship('Alpha')
    const fit = shipFitOf([
      moduleIn(parent, 'DroneBay', 200, 'Hobgoblin II', { quantity: 3 }),
      moduleIn(parent, 'DroneBay', 200, 'Hobgoblin II', { quantity: 2 }),
      moduleIn(parent, 'Cargo', 201, 'Nanite Repair Paste', { quantity: 20 }),
    ])

    expect(fit.drones).toEqual([{ typeId: 200, typeName: 'Hobgoblin II', quantity: 5 }])
    expect(fit.cargo).toEqual([{ typeId: 201, typeName: 'Nanite Repair Paste', quantity: 20 }])
  })
})

describe('groupAssembledShips', () => {
  it('groups ships that share type, name and modules per slot kind, whatever the slot index', () => {
    const first = ship('Alpha')
    const second = ship('Alpha')
    const contents = [
      moduleIn(first, 'HiSlot0', 100, 'Autocannon'),
      moduleIn(first, 'MedSlot0', 102, 'Microwarpdrive'),
      moduleIn(second, 'HiSlot3', 100, 'Autocannon'),
      moduleIn(second, 'MedSlot1', 102, 'Microwarpdrive'),
    ]

    const groups = groupAssembledShips([first, second], contentsMap(contents))

    expect(groups).toHaveLength(1)
    expect(groups[0].ships).toEqual([first, second])
    expect(groups[0].representative).toBe(first)
  })

  it('splits ships with a different name', () => {
    expect(groupAssembledShips([ship('Alpha'), ship('Bravo')], contentsMap([]))).toHaveLength(2)
  })

  it('splits ships with a different hull type', () => {
    const other = ship('Alpha', { typeId: 603, typeName: 'Merlin' })

    expect(groupAssembledShips([ship('Alpha'), other], contentsMap([]))).toHaveLength(2)
  })

  it('splits ships whose same module sits in another slot kind', () => {
    const first = ship('Alpha')
    const second = ship('Alpha')
    const contents = [moduleIn(first, 'HiSlot0', 100, 'Module'), moduleIn(second, 'MedSlot0', 100, 'Module')]

    expect(groupAssembledShips([first, second], contentsMap(contents))).toHaveLength(2)
  })

  it('splits ships with one module more', () => {
    const first = ship('Alpha')
    const second = ship('Alpha')
    const contents = [moduleIn(first, 'HiSlot0', 100, 'Module'), moduleIn(second, 'HiSlot0', 100, 'Module'), moduleIn(second, 'HiSlot1', 100, 'Module')]

    expect(groupAssembledShips([first, second], contentsMap(contents))).toHaveLength(2)
  })

  it('keeps ships together when only their cargo differs', () => {
    const first = ship('Alpha')
    const second = ship('Alpha')
    const contents = [moduleIn(first, 'Cargo', 201, 'Nanite Repair Paste', { quantity: 20 })]

    expect(groupAssembledShips([first, second], contentsMap(contents))).toHaveLength(1)
  })

  it('flags a group whose name contains bad fit, whatever the case', () => {
    const groups = groupAssembledShips([ship('Rifter BAD Fit'), ship('Good one')], contentsMap([]))

    expect(groups.map(group => group.badFit)).toEqual([true, false])
  })
})

describe('isBadFitName', () => {
  it.each(['bad fit', 'Rifter Bad Fit v2', 'BAD FIT'])('flags %s', name => {
    expect(isBadFitName(name)).toBe(true)
  })

  it.each(['Badfit', 'good fit', '', null])('does not flag %s', name => {
    expect(isBadFitName(name)).toBe(false)
  })
})

describe('formatEft', () => {
  const EXPECTED_EFT = [
    '[Rifter, Alpha]',
    'Damage Control II',
    '',
    'Experimental 10MN Microwarpdrive I',
    '',
    '200mm AutoCannon II',
    '200mm AutoCannon II',
    '',
    'Small Core Defense Field Extender I',
    '',
    'Hobgoblin II x5',
    '',
    'Nanite Repair Paste x20',
  ].join('\n')

  function fittedAlpha(): { parent: Asset; contents: Asset[] } {
    const parent = ship('Alpha')
    const contents = [
      moduleIn(parent, 'HiSlot1', 1, '200mm AutoCannon II'),
      moduleIn(parent, 'HiSlot0', 1, '200mm AutoCannon II'),
      moduleIn(parent, 'HiSlot0', 2, 'EMP S', { categoryId: CHARGE_CATEGORY_ID, quantity: 40 }),
      moduleIn(parent, 'MedSlot0', 3, 'Experimental 10MN Microwarpdrive I'),
      moduleIn(parent, 'LoSlot0', 4, 'Damage Control II'),
      moduleIn(parent, 'RigSlot0', 5, 'Small Core Defense Field Extender I'),
      moduleIn(parent, 'DroneBay', 6, 'Hobgoblin II', { quantity: 5 }),
      moduleIn(parent, 'Cargo', 7, 'Nanite Repair Paste', { quantity: 20 }),
    ]
    return { parent, contents }
  }

  it('writes the header, the slot sections in low mid high rig order, then drones and cargo', () => {
    const { parent, contents } = fittedAlpha()
    const [group] = groupAssembledShips([parent], contentsMap(contents))

    expect(formatEft(group)).toBe(EXPECTED_EFT)
  })

  it('uses the hull name as fit name when the ship has no custom name', () => {
    const [group] = groupAssembledShips([ship(null)], contentsMap([]))

    expect(formatEft(group)).toBe('[Rifter, Rifter]')
  })

  it('writes subsystems after rigs', () => {
    const parent = ship('Alpha', { typeId: 29984, typeName: 'Tengu' })
    const contents = [moduleIn(parent, 'SubSystemSlot0', 9, 'Tengu Defensive'), moduleIn(parent, 'RigSlot0', 5, 'Rig')]
    const [group] = groupAssembledShips([parent], contentsMap(contents))

    expect(formatEft(group)).toBe(['[Tengu, Alpha]', 'Rig', '', 'Tengu Defensive'].join('\n'))
  })

  it('returns null when a module has no known type name', () => {
    const parent = ship('Alpha')
    const [group] = groupAssembledShips([parent], contentsMap([moduleIn(parent, 'HiSlot0', 1, null)]))

    expect(formatEft(group)).toBeNull()
  })

  it('returns null when the hull has no known type name', () => {
    const [group] = groupAssembledShips([ship('Alpha', { typeName: null })], contentsMap([]))

    expect(formatEft(group)).toBeNull()
  })
})
