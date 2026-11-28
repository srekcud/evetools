import { planStockTransfers } from '@/utils/planetarySupply'
import { FERTILIZER, feedOf, launchpadOf } from '../planetarySupplyFixtures'
import {
  ALPHA,
  BRAVO,
  CARL,
  HOLD,
  TRANSFER_DAYS,
  X,
  Y,
  alphaFirstLaunchpad,
  alphaSecondLaunchpad,
  bravoLaunchpad,
  holdersOf,
  knownHolder,
  mixedVolumeLaunchpad,
  referenceHolders,
  transferFeed,
  transferLaunchpads,
  unknownHolder,
} from '../planetarySupplyTransferFixtures'

const ALPHA_ROW_KEYS = ['colony-alpha-1101', 'colony-alpha-2-1102']
const BRAVO_ROW_KEYS = ['colony-bravo-1103']

function line(typeId: number, typeName: string | null, need: number, transfer: number | null, volumeM3: number | null) {
  return { typeId, typeName, need, transfer, volumeM3 }
}

function knownStockHolder(character: { characterId: number; characterName: string }, available: number) {
  return { ...character, available, unknownReason: null }
}

function unknownStockHolder(character: { characterId: number; characterName: string }, unknownReason: string) {
  return { ...character, available: null, unknownReason }
}

describe('planStockTransfers recipients', () => {
  it('E1 brings Alpha 900 X and 500 Y for 1 050 m3 and Bravo 400 X for 300 m3, sorted by name, Hold without launchpad receiving nothing', () => {
    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, referenceHolders())

    expect(plan.recipients).toEqual([
      {
        ...ALPHA,
        lines: [line(X, 'Coolant', 1200, 900, 675), line(Y, 'Mechanical Parts', 500, 500, 375)],
        totalM3: 1050,
        excludedCount: 0,
        rowKeys: ALPHA_ROW_KEYS,
      },
      {
        ...BRAVO,
        lines: [line(X, 'Coolant', 400, 400, 300)],
        totalM3: 300,
        excludedCount: 0,
        rowKeys: BRAVO_ROW_KEYS,
      },
    ])
  })

  it('E2 brings Alpha no X when Alpha holds 2 000 X at the home location, its own stock covering its need of 1 200 first', () => {
    const holders = holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 2000 }]),
      knownHolder(BRAVO, []),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => [recipient.characterName, recipient.lines, recipient.totalM3])).toEqual([
      ['Alpha', [line(X, 'Coolant', 1200, 0, 0), line(Y, 'Mechanical Parts', 500, 500, 375)], 375],
      ['Bravo', [line(X, 'Coolant', 400, 400, 300)], 300],
    ])
    expect(plan.types.map(type => [type.typeId, type.holders, type.shortfall])).toEqual([
      [X, [knownStockHolder(HOLD, 3000), knownStockHolder(ALPHA, 800)], 0],
      [Y, [knownStockHolder(HOLD, 100)], 400],
    ])
  })

  it('E4 keeps the need of Bravo at 400 X with an unknown transfer and volume when its assets were never read', () => {
    const holders = holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      unknownHolder(BRAVO, 'assets_never_read'),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => [recipient.characterName, recipient.lines, recipient.totalM3])).toEqual([
      ['Alpha', [line(X, 'Coolant', 1200, 900, 675), line(Y, 'Mechanical Parts', 500, 500, 375)], 1050],
      ['Bravo', [line(X, 'Coolant', 400, null, null)], null],
    ])
    expect(plan.types).toEqual([
      { typeId: X, typeName: 'Coolant', holders: [knownStockHolder(HOLD, 3000), unknownStockHolder(BRAVO, 'assets_never_read')], shortfall: null },
      { typeId: Y, typeName: 'Mechanical Parts', holders: [knownStockHolder(HOLD, 100), unknownStockHolder(BRAVO, 'assets_never_read')], shortfall: null },
    ])
  })

  it('E6 brings Alpha 700 X from the only known launchpad and counts the launchpad of unknown capacity as excluded', () => {
    const launchpads = [alphaFirstLaunchpad(), alphaSecondLaunchpad({ capacityM3: null }), bravoLaunchpad()]

    const plan = planStockTransfers(launchpads, TRANSFER_DAYS, referenceHolders())

    expect(plan.recipients.filter(recipient => recipient.characterId === ALPHA.characterId)).toEqual([{
      ...ALPHA,
      lines: [line(X, 'Coolant', 1000, 700, 525), line(Y, 'Mechanical Parts', 500, 500, 375)],
      totalM3: 900,
      excludedCount: 1,
      rowKeys: ALPHA_ROW_KEYS,
    }])
  })

  it('E7 keeps every need with unknown transfers, volumes and shortfalls when the home location is not set', () => {
    const holders = holdersOf([knownHolder(ALPHA, []), knownHolder(BRAVO, []), knownHolder(HOLD, [])], null)

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => [recipient.lines, recipient.totalM3])).toEqual([
      [[line(X, 'Coolant', 1200, null, null), line(Y, 'Mechanical Parts', 500, null, null)], null],
      [[line(X, 'Coolant', 400, null, null)], null],
    ])
    expect(plan.types.map(type => [type.typeId, type.holders, type.shortfall])).toEqual([[X, [], null], [Y, [], null]])
  })

  it('keeps a type without name with a null name instead of a placeholder', () => {
    const launchpads = [bravoLaunchpad({ feeds: [feedOf(FERTILIZER, { typeName: null, dailyRate: 60, volumePerUnit: 0.5 })] })]
    const holders = holdersOf([knownHolder(BRAVO, []), knownHolder(HOLD, [{ typeId: FERTILIZER, quantity: 1000 }])])

    const plan = planStockTransfers(launchpads, TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => recipient.lines)).toEqual([[line(FERTILIZER, null, 240, 240, 120)]])
    expect(plan.types).toEqual([{ typeId: FERTILIZER, typeName: null, holders: [knownStockHolder(HOLD, 1000)], shortfall: 0 }])
  })

  it('has no recipient and no type for 0 days and plans again for 4 days', () => {
    const empty = planStockTransfers(transferLaunchpads(), 0, referenceHolders())
    const planned = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, referenceHolders())

    expect(empty).toEqual({ recipients: [], types: [] })
    expect(planned.recipients.map(recipient => recipient.characterName)).toEqual(['Alpha', 'Bravo'])
  })
})

describe('planStockTransfers holders and shortfall', () => {
  it('E1 lists Hold as the only holder of 3 000 X and 100 Y and leaves a shortfall of 400 Y only', () => {
    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, referenceHolders())

    expect(plan.types).toEqual([
      { typeId: X, typeName: 'Coolant', holders: [knownStockHolder(HOLD, 3000)], shortfall: 0 },
      { typeId: Y, typeName: 'Mechanical Parts', holders: [knownStockHolder(HOLD, 100)], shortfall: 400 },
    ])
  })

  it('E3 lists Hold with 3 000 X before Carl with 500 X, Carl having no launchpad', () => {
    const holders = holdersOf([
      knownHolder(ALPHA, []),
      knownHolder(BRAVO, []),
      knownHolder(CARL, [{ typeId: X, quantity: 500 }]),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }]),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.types.map(type => [type.typeId, type.holders, type.shortfall])).toEqual([
      [X, [knownStockHolder(HOLD, 3000), knownStockHolder(CARL, 500)], 0],
      [Y, [], 500],
    ])
  })

  it('E5 lists Hold as an unknown holder and makes every shortfall unknown when its assets scope is missing', () => {
    const holders = holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      knownHolder(BRAVO, []),
      unknownHolder(HOLD, 'missing_assets_scope'),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => recipient.totalM3)).toEqual([1050, 300])
    expect(plan.types).toEqual([
      { typeId: X, typeName: 'Coolant', holders: [unknownStockHolder(HOLD, 'missing_assets_scope')], shortfall: null },
      { typeId: Y, typeName: 'Mechanical Parts', holders: [unknownStockHolder(HOLD, 'missing_assets_scope')], shortfall: null },
    ])
  })
})

describe('planStockTransfers ordering', () => {
  it('sorts the recipients by name whatever the order of the launchpads', () => {
    const launchpads = [bravoLaunchpad(), alphaFirstLaunchpad(), alphaSecondLaunchpad()]

    const plan = planStockTransfers(launchpads, TRANSFER_DAYS, referenceHolders())

    expect(plan.recipients.map(recipient => [recipient.characterName, recipient.rowKeys])).toEqual([
      ['Alpha', ALPHA_ROW_KEYS],
      ['Bravo', BRAVO_ROW_KEYS],
    ])
  })

  it('lists the lines and the types by type id even when the launchpad feeds Y before X', () => {
    const launchpads = [alphaFirstLaunchpad({ feeds: [transferFeed(Y, 125), transferFeed(X, 250)] })]
    const holders = holdersOf([knownHolder(ALPHA, []), knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }])])

    const plan = planStockTransfers(launchpads, TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => recipient.lines.map(candidate => candidate.typeId))).toEqual([[X, Y]])
    expect(plan.types.map(type => type.typeId)).toEqual([X, Y])
  })

  it('sorts the known holders of equal available quantity by name and then the unknown holders by name', () => {
    const holders = holdersOf([
      unknownHolder(BRAVO, 'missing_assets_scope'),
      knownHolder(HOLD, [{ typeId: X, quantity: 500 }]),
      unknownHolder(ALPHA, 'assets_never_read'),
      knownHolder(CARL, [{ typeId: X, quantity: 500 }]),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.types[0].holders).toEqual([knownStockHolder(CARL, 500), knownStockHolder(HOLD, 500), unknownStockHolder(ALPHA, 'assets_never_read'), unknownStockHolder(BRAVO, 'missing_assets_scope')])
  })
})

describe('planStockTransfers volumes', () => {
  it('multiplies each transfer by the volume of its own type, 500 Y at 0.5 m3 weighing 250 m3 next to 1 000 X at 0.75 m3', () => {
    const holders = holdersOf([knownHolder(ALPHA, []), knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }])])

    const plan = planStockTransfers([mixedVolumeLaunchpad()], TRANSFER_DAYS, holders)

    expect(plan.recipients).toEqual([{
      ...ALPHA,
      lines: [line(X, 'Coolant', 1000, 1000, 750), line(Y, 'Mechanical Parts', 500, 500, 250)],
      totalM3: 1000,
      excludedCount: 0,
      rowKeys: ['colony-alpha-1101'],
    }])
  })

  it('takes the volume of a type from the known launchpads only, ignoring a launchpad left out for an unknown input volume', () => {
    const excludedFirst = launchpadOf({
      ...ALPHA,
      colonyId: 'colony-alpha-2',
      planetName: 'Alpha II',
      pinId: 1_102,
      feeds: [feedOf(X, { dailyRate: 50, volumePerUnit: 3 }), feedOf(Y, { dailyRate: 50, volumePerUnit: null })],
    })
    const holders = holdersOf([knownHolder(ALPHA, []), knownHolder(HOLD, [{ typeId: X, quantity: 3000 }])])

    const plan = planStockTransfers([excludedFirst, mixedVolumeLaunchpad()], TRANSFER_DAYS, holders)

    expect(plan.recipients).toEqual([{
      ...ALPHA,
      lines: [line(X, 'Coolant', 1000, 1000, 750), line(Y, 'Mechanical Parts', 500, 500, 250)],
      totalM3: 1000,
      excludedCount: 1,
      rowKeys: ['colony-alpha-2-1102', 'colony-alpha-1101'],
    }])
  })
})

describe('planStockTransfers recipients left without a known launchpad or a known holder entry', () => {
  it('keeps a recipient whose launchpads are all unknown with no line, its excluded count and an unknown total instead of 0 m3', () => {
    const launchpads = [alphaFirstLaunchpad({ capacityM3: null }), alphaSecondLaunchpad({ capacityM3: null }), bravoLaunchpad()]

    const plan = planStockTransfers(launchpads, TRANSFER_DAYS, referenceHolders())

    expect(plan.recipients).toEqual([
      { ...ALPHA, lines: [], totalM3: null, excludedCount: 2, rowKeys: ALPHA_ROW_KEYS },
      { ...BRAVO, lines: [line(X, 'Coolant', 400, 400, 300)], totalM3: 300, excludedCount: 0, rowKeys: BRAVO_ROW_KEYS },
    ])
  })

  it('makes the transfer and the volume of a recipient absent from the holders unknown, and the shortfall of its type unknown', () => {
    const holders = holdersOf([
      knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
      knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
    ])

    const plan = planStockTransfers(transferLaunchpads(), TRANSFER_DAYS, holders)

    expect(plan.recipients.map(recipient => [recipient.characterName, recipient.lines, recipient.totalM3])).toEqual([
      ['Alpha', [line(X, 'Coolant', 1200, 900, 675), line(Y, 'Mechanical Parts', 500, 500, 375)], 1050],
      ['Bravo', [line(X, 'Coolant', 400, null, null)], null],
    ])
    expect(plan.types.map(type => [type.typeId, type.shortfall])).toEqual([[X, null], [Y, 400]])
  })
})
