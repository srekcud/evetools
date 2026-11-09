import { buildShareRecap, shareRecapLines } from '@/views/MiningOperation/miningOperationShareRecap'
import { OPERATION_O, OPERATION_P2, ledger } from './miningOperationLedgerFixtures'
import { CYRA_BOOSTING_ON_JITA_SELL } from './miningOperationValuationFixtures'
import {
  BITUMENS_MINED,
  FOUR_MINED_TYPES,
  KERNITE_MINED,
  PARTICIPANT_NAME_PARTS,
  PROPORTIONAL_RECAP_LINES,
  RECAP_BASIS,
  SPODUMAIN_MINED,
  UNKNOWN_PILOT_LABEL,
  minedLedger,
  minedLedgerWithBrannUnknown,
  recapOperation,
  recapOperationEachForThemselves,
  recapUnitPrices,
  recapValuation,
  recapValuationEachForThemselves,
  recapValuationWithBrannUnknown,
  translator,
} from './miningOperationShareRecapFixtures'
import { BITUMENS_TYPE_ID } from './miningOperationValuationFixtures'

const t = translator()
const SIXTY_CHARACTER_LABEL = 'Anomalie Spodumain de la ceinture haute — relève de nuit 20h'

function textOf(recap: ReturnType<typeof buildShareRecap>): string {
  return shareRecapLines(recap, RECAP_BASIS, t).join('\n')
}

describe('buildShareRecap', () => {
  it('references the operation with the last 6 hexadecimal characters of its id, upper cased', () => {
    const recapOfO = buildShareRecap(recapOperation({ id: OPERATION_O }), recapValuation(), minedLedger(), UNKNOWN_PILOT_LABEL)
    const recapOfP2 = buildShareRecap(recapOperation({ id: OPERATION_P2 }), recapValuation(), minedLedger(), UNKNOWN_PILOT_LABEL)

    expect([recapOfO.reference, recapOfP2.reference]).toEqual(['OP-2B7A53', 'OP-7B2E14'])
  })

  it('keeps the 3 top ores by decreasing value, each valued as the sum of the quantities of all participants times its unit price', () => {
    const recap = buildShareRecap(recapOperation(), recapValuation(), minedLedger(FOUR_MINED_TYPES), UNKNOWN_PILOT_LABEL)

    expect(recap.topOres).toEqual([
      { typeName: 'Spodumain', value: 1_200_000_000 },
      { typeName: 'Bitumens', value: 800_000_000 },
      { typeName: 'Kernite', value: 48_000_000 },
    ])
  })

  it('values an ore without unit price as unknown, ranked after the valued ores, and names the missing type as the cause', () => {
    const valuation = recapValuation({ totalValue: null, boosterCut: null, iskPerHour: null, unitPrices: recapUnitPrices({ [BITUMENS_TYPE_ID]: null }) })

    const recap = buildShareRecap(recapOperation(), valuation, minedLedger([SPODUMAIN_MINED, BITUMENS_MINED, KERNITE_MINED]), UNKNOWN_PILOT_LABEL)

    expect({ topOres: recap.topOres, totalValue: recap.totalValue, unknownCauses: recap.unknownCauses }).toEqual({
      topOres: [
        { typeName: 'Spodumain', value: 1_200_000_000 },
        { typeName: 'Kernite', value: 48_000_000 },
        { typeName: 'Bitumens', value: null },
      ],
      totalValue: null,
      unknownCauses: 'Bitumens',
    })
    expect(textOf(recap).split('\n')).toEqual(expect.arrayContaining([
      `Value: unknown (Bitumens) · ${RECAP_BASIS}`,
      'Top ores: Spodumain 1.20B · Kernite 48.00M · Bitumens unknown',
    ]))
  })

  it('tells the top ores and the value unknown when a participant quantity is unknown, without naming that participant', () => {
    const recap = buildShareRecap(recapOperation(), recapValuationWithBrannUnknown(), minedLedgerWithBrannUnknown(), UNKNOWN_PILOT_LABEL)

    expect({ topOres: recap.topOres, totalValue: recap.totalValue, iskPerHour: recap.iskPerHour, unknownCauses: recap.unknownCauses }).toEqual({
      topOres: null,
      totalValue: null,
      iskPerHour: null,
      unknownCauses: UNKNOWN_PILOT_LABEL,
    })
    expect(textOf(recap)).toBe([
      'Anomalie Spodumain — 20:00 · Aldranette · 2026-11-05 20:00 → 00:00 EVE',
      `Value: unknown (${UNKNOWN_PILOT_LABEL}) · ${RECAP_BASIS}`,
      'ISK/h: unknown · duration 1 h 30 · 3 participants',
      'Top ores: unknown',
      'Booster share 10 %: unknown',
      'evetools · OP-2B7A53',
    ].join('\n'))
  })
})

describe('shareRecapLines', () => {
  it('writes one line per segment for a value prorata operation with a 10 % booster share', () => {
    const recap = buildShareRecap(recapOperation(), recapValuation(), minedLedger(), UNKNOWN_PILOT_LABEL)

    expect(textOf(recap)).toBe(PROPORTIONAL_RECAP_LINES.join('\n'))
  })

  it('tells that nobody gets a share and keeps no reference when each participant keeps their own ore', () => {
    const recap = buildShareRecap(recapOperationEachForThemselves(), recapValuationEachForThemselves(), minedLedger(), UNKNOWN_PILOT_LABEL)

    expect(textOf(recap)).toBe([
      ...PROPORTIONAL_RECAP_LINES.slice(0, 4),
      'Each for themselves: no shares',
      'evetools · each for themselves',
    ].join('\n'))
  })

  it('never writes the name of a participant, a character, the owner or the booster, known or unknown value alike', () => {
    const known = buildShareRecap(recapOperation(), recapValuation({ settings: CYRA_BOOSTING_ON_JITA_SELL }), minedLedger(), UNKNOWN_PILOT_LABEL)
    const unknown = buildShareRecap(recapOperation(), recapValuationWithBrannUnknown(), minedLedgerWithBrannUnknown(), UNKNOWN_PILOT_LABEL)

    const texts = [textOf(known), textOf(unknown)]

    expect(texts.map(text => text.startsWith('Anomalie Spodumain'))).toEqual([true, true])
    for (const name of PARTICIPANT_NAME_PARTS) {
      expect(texts.map(text => text.includes(name)), name).toEqual([false, false])
      expect(JSON.stringify([known, unknown]).includes(name), `${name} in the recap data`).toBe(false)
    }
  })

  it('keeps a 60 character operation label whole', () => {
    expect(SIXTY_CHARACTER_LABEL).toHaveLength(60)
    const recap = buildShareRecap(recapOperation({ label: SIXTY_CHARACTER_LABEL }), recapValuation(), minedLedger(), UNKNOWN_PILOT_LABEL)

    expect({ label: recap.label, firstLine: textOf(recap).split('\n')[0] }).toEqual({
      label: SIXTY_CHARACTER_LABEL,
      firstLine: `${SIXTY_CHARACTER_LABEL} · Aldranette · 2026-11-05 20:00 → 00:00 EVE`,
    })
  })

  it('omits the system, the period, the top ores and the booster share when they are absent', () => {
    const operation = recapOperation({ solarSystemName: null, endedAt: null, valuationSettings: { ...CYRA_BOOSTING_ON_JITA_SELL, boosterCutPercent: 0 } })
    const valuation = recapValuation({ settings: { ...CYRA_BOOSTING_ON_JITA_SELL, boosterCutPercent: 0 }, boosterCut: null })

    const recap = buildShareRecap(operation, valuation, ledger({ status: 'shares', participants: [] }), UNKNOWN_PILOT_LABEL)

    expect(textOf(recap)).toBe([
      'Anomalie Spodumain — 20:00',
      `Value: 2.05B ISK · ${RECAP_BASIS}`,
      'ISK/h: 512.75M/h · duration 4 h 00 · 3 participants',
      'evetools · OP-2B7A53',
    ].join('\n'))
  })
})
