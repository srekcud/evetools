import { buildPickupPlan, sortBringBack } from '@/utils/planetaryPickup'
import {
  coolantFromAmarr,
  coolantFromPerimeter,
  customsOfficeStockOf,
  homePlasmoidsFromJitaVII,
  mechanicalPartsFromAmarr,
  pendingOutputOf,
  unknownFertilizerFromAmarrVIII,
  unlocatedWaterInCustomsOffice,
  waterFromAmarr,
} from '../planetaryPendingStockFixtures'
import { COOLANT, FERTILIZER, MECHANICAL_PARTS, PLASMOIDS, TYPE_NAMES, WATER } from '../planetarySupplyFixtures'

const TYPE_NAME_BY_ID = new Map<number, string | null>(Object.entries(TYPE_NAMES).map(([typeId, name]) => [Number(typeId), name]))

function sourcesOf(row: { sources: { planetName: string | null; quantity: number | null; volumeM3: number | null; dataStale: boolean }[] }) {
  return row.sources.map(source => [source.planetName, source.quantity, source.volumeM3, source.dataStale])
}

describe('buildPickupPlan', () => {
  it('picks up the smaller of what is needed and what is pending and keeps the rest to buy', () => {
    const rows = buildPickupPlan(
      [{ typeId: COOLANT, quantity: 11520 }, { typeId: WATER, quantity: 800 }],
      TYPE_NAME_BY_ID,
      [coolantFromAmarr(), coolantFromPerimeter(), waterFromAmarr()],
      [],
    )

    expect(rows.map(row => [row.typeId, row.typeName, row.needed, row.pending, row.pickup, row.remaining])).toEqual([
      [COOLANT, 'Coolant', 11520, 5500, 5500, 6020],
      [WATER, 'Water', 800, 2200, 800, 0],
    ])
    expect(sourcesOf(rows[0])).toEqual([['Amarr III', 4000, 760, false], ['Perimeter II', 1500, 285, false]])
    expect(sourcesOf(rows[1])).toEqual([['Amarr III', 2200, 616, false]])
  })

  it('leaves a source with an unknown quantity out of the sum and reports it apart', () => {
    const rows = buildPickupPlan(
      [{ typeId: FERTILIZER, quantity: 960 }],
      TYPE_NAME_BY_ID,
      [
        unknownFertilizerFromAmarrVIII(),
        unknownFertilizerFromAmarrVIII({ colonyId: 'colony-jita-vii', planetName: 'Jita VII', quantity: 310, volumeM3: 235.6 }),
      ],
      [],
    )

    expect(rows).toHaveLength(1)
    expect([rows[0].pending, rows[0].pickup, rows[0].remaining]).toEqual([310, 310, 650])
    expect(sourcesOf(rows[0])).toEqual([['Jita VII', 310, 235.6, false]])
    expect(rows[0].unknownSources.map(source => [source.planetName, source.quantity])).toEqual([['Amarr VIII', null]])
  })

  it('shows a needed type without pending output with 0 picked up and ignores outputs without a consuming colony', () => {
    const rows = buildPickupPlan(
      [{ typeId: MECHANICAL_PARTS, quantity: 0 }, { typeId: PLASMOIDS, quantity: 720 }, { typeId: FERTILIZER, quantity: 960 }],
      TYPE_NAME_BY_ID,
      [homePlasmoidsFromJitaVII(), mechanicalPartsFromAmarr(), pendingOutputOf({ typeId: FERTILIZER, quantity: 90, consumers: [], destination: 'home' })],
      [],
    )

    expect(rows.map(row => [row.typeId, row.needed, row.pending, row.pickup, row.remaining, row.sources.length, row.unknownSources.length])).toEqual([
      [PLASMOIDS, 720, 0, 0, 720, 0, 0],
      [FERTILIZER, 960, 0, 0, 960, 0, 0],
    ])
  })

  it('adds a customs office source per line to the needed types, probable with its planet or unlocated, and counts it as pending', () => {
    const rows = buildPickupPlan(
      [{ typeId: COOLANT, quantity: 11520 }, { typeId: WATER, quantity: 300 }, { typeId: MECHANICAL_PARTS, quantity: 0 }],
      TYPE_NAME_BY_ID,
      [coolantFromAmarr()],
      [
        customsOfficeStockOf(),
        unlocatedWaterInCustomsOffice(),
        customsOfficeStockOf({ typeId: MECHANICAL_PARTS, quantity: 640, volumeM3: 243.2 }),
        customsOfficeStockOf({ typeId: PLASMOIDS, quantity: 120, volumeM3: 68.4 }),
      ],
    )

    const customsOf = (row: (typeof rows)[number]) => row.sources
      .filter(source => source.kind === 'customs')
      .map(source => [source.planetName, source.probable, source.quantity, source.volumeM3])
    expect(rows.map(row => [row.typeId, row.needed, row.pending, row.pickup, row.remaining])).toEqual([
      [COOLANT, 11520, 4900, 4900, 6620],
      [WATER, 300, 350, 300, 0],
    ])
    expect(customsOf(rows[0])).toEqual([['Amarr III', true, 900, 171]])
    expect(customsOf(rows[1])).toEqual([[null, false, 350, 98]])
    expect(rows[0].sources.filter(source => source.kind === 'colony').map(source => [source.planetName, source.quantity])).toEqual([['Amarr III', 4000]])
  })
})

describe('sortBringBack', () => {
  it('puts the outputs bound for another colony first, then orders by planet name, without touching the input', () => {
    const outputs = [
      homePlasmoidsFromJitaVII({ planetName: 'Aufay' }),
      coolantFromPerimeter(),
      homePlasmoidsFromJitaVII({ planetName: 'Zarzakh' }),
      coolantFromAmarr(),
    ]

    const sorted = sortBringBack(outputs)

    expect(sorted.map(output => [output.destination, output.planetName])).toEqual([
      ['colony', 'Amarr III'],
      ['colony', 'Perimeter II'],
      ['home', 'Aufay'],
      ['home', 'Zarzakh'],
    ])
    expect(outputs.map(output => output.planetName)).toEqual(['Aufay', 'Perimeter II', 'Zarzakh', 'Amarr III'])
  })
})
