import { parseSurveyPaste, type SurveyOreType } from '@/views/MiningSite/miningSurveyPaste'

const ARKONOR = 22
const DUCINIUM_II = 74533
const UEGANITE = 82205
const VELDSPAR = 1230

const ORE_TYPES: SurveyOreType[] = [
  { typeId: ARKONOR, typeName: 'Arkonor', volume: 16 },
  { typeId: DUCINIUM_II, typeName: 'Ducinium II-Grade', volume: 16 },
  { typeId: UEGANITE, typeName: 'Ueganite', volume: 5 },
  { typeId: VELDSPAR, typeName: 'Veldspar', volume: 0.1 },
]

function pasted(...rows: string[]): string {
  return rows.join('\n')
}

describe('parseSurveyPaste', () => {
  it('reads a tab separated survey in the french format, ignoring value and distance', () => {
    const text = pasted(
      'Arkonor\t31.026\t496.416 m3\t81.500.000,00 ISK\t6.952 m',
      'Veldspar\t12.345\t1.234,5 m³\t3.100.000,00 ISK\t12 km',
    )

    expect(parseSurveyPaste(text, ORE_TYPES)).toEqual({
      lines: [{ typeId: ARKONOR, units: 31_026 }, { typeId: VELDSPAR, units: 12_345 }],
      issues: [],
    })
  })

  it('sums the units of the lines of a same type and keeps the order of first appearance', () => {
    const text = pasted(
      'Arkonor\t31.026\t496.416 m3\t-\t1 km',
      'Ueganite\t2.000\t10.000 m3\t-\t2 km',
      'Arkonor\t33.641\t538.256 m3\t-\t3 km',
      'Veldspar\t5.000\t500 m3\t-\t4 km',
      'Arkonor\t35.333\t565.328 m3\t-\t5 km',
      'Ueganite\t1.500\t7.500 m3\t-\t6 km',
    )

    expect(parseSurveyPaste(text, ORE_TYPES)).toEqual({
      lines: [{ typeId: ARKONOR, units: 100_000 }, { typeId: UEGANITE, units: 3_500 }, { typeId: VELDSPAR, units: 5_000 }],
      issues: [],
    })
  })

  it('reads the english format with comma thousands and a decimal point in the volume', () => {
    const text = pasted(
      'Ueganite\t103,856\t519,280 m3\t33,100,000.00 ISK\t14 km',
      'Veldspar\t12,345\t1,234.5 m3\t3,100,000.00 ISK\t12 km',
    )

    expect(parseSurveyPaste(text, ORE_TYPES)).toEqual({
      lines: [{ typeId: UEGANITE, units: 103_856 }, { typeId: VELDSPAR, units: 12_345 }],
      issues: [],
    })
  })

  it('reads space, no-break space and narrow no-break space thousands and the two spaces column fallback', () => {
    const text = pasted(
      'Ueganite\t103 856\t519 280 m³\t-\t14 km',
      'Veldspar\t12 345\t1 234,5 m³\t-\t12 km',
      'Arkonor  31.026  496.416 m3  -  1 km',
    )

    expect(parseSurveyPaste(text, ORE_TYPES)).toEqual({
      lines: [{ typeId: UEGANITE, units: 103_856 }, { typeId: VELDSPAR, units: 12_345 }, { typeId: ARKONOR, units: 31_026 }],
      issues: [],
    })
  })

  it('matches the name of the SDE ignoring case and surrounding spaces and never reads the dash value as 0', () => {
    const text = pasted(
      'Ducinium II-Grade\t9.789\t156.624 m3\t-\t9.386 m',
      'ARKONOR\t31.026\t496.416 m3\t-\t1 km',
      '  ueganite  \t2.000\t10.000 m3\t-\t3 km',
    )

    expect(parseSurveyPaste(text, ORE_TYPES)).toEqual({
      lines: [{ typeId: DUCINIUM_II, units: 9_789 }, { typeId: ARKONOR, units: 31_026 }, { typeId: UEGANITE, units: 2_000 }],
      issues: [],
    })
  })

  it('keeps reading around an unreadable line and an unknown ore, with the line numbers of the pasted text', () => {
    const text = pasted(
      'Arkonor\t31.026\t496.416 m3\t-\t1 km',
      'Arkonor\tabc\t1 m3\t-\t1 km',
      'Ueganite\t2.000\t10.000 m3\t-\t2 km',
      'Unobtainium\t10.000\t50.000 m3\t-\t3 km',
    )

    const result = parseSurveyPaste(text, ORE_TYPES)

    expect(result.lines).toEqual([{ typeId: ARKONOR, units: 31_026 }, { typeId: UEGANITE, units: 2_000 }])
    expect(result.issues).toMatchObject([
      { kind: 'unreadable', line: 2 },
      { kind: 'unknown_ore', line: 4, name: 'Unobtainium' },
    ])
    expect(result.issues).toHaveLength(2)
  })

  it('refuses a collapsed group by naming the ore, alone or mixed with unfolded lines of the same ore', () => {
    const collapsedOnly = pasted(
      'Ueganite [11] 112 ISK / m³',
      'Veldspar [2]\t45 ISK / m³',
      'Arkonor [3] - ISK / m³',
    )
    const mixed = pasted(
      'Arkonor\t31.026\t496.416 m3\t-\t1 km',
      'Ueganite [11] 112 ISK / m³',
      'Ueganite\t2.000\t10.000 m3\t-\t1 km',
    )

    const alone = parseSurveyPaste(collapsedOnly, ORE_TYPES)
    const withUnfolded = parseSurveyPaste(mixed, ORE_TYPES)

    expect(alone.lines).toEqual([])
    expect(alone.issues).toMatchObject([
      { kind: 'collapsed_group', line: 1, name: 'Ueganite' },
      { kind: 'collapsed_group', line: 2, name: 'Veldspar' },
      { kind: 'collapsed_group', line: 3, name: 'Arkonor' },
    ])
    expect(alone.issues).toHaveLength(3)
    expect(withUnfolded.issues).toMatchObject([{ kind: 'collapsed_group', line: 2, name: 'Ueganite' }])
    expect(withUnfolded.issues).toHaveLength(1)
  })

  it('refuses a volume that does not match the units beyond max(1 m3, 1 %) and accepts it within', () => {
    const text = pasted(
      'Arkonor\t31.026\t400.000 m3\t-\t1 km',
      'Arkonor\t31.026\t500.000 m3\t-\t1 km',
      'Veldspar\t5\t1 m3\t-\t1 km',
      'Veldspar\t5\t3 m3\t-\t1 km',
    )

    const result = parseSurveyPaste(text, ORE_TYPES)

    expect(result.lines).toEqual([{ typeId: ARKONOR, units: 31_026 }, { typeId: VELDSPAR, units: 5 }])
    expect(result.issues).toMatchObject([
      { kind: 'volume_mismatch', line: 1 },
      { kind: 'volume_mismatch', line: 4 },
    ])
    expect(result.issues).toHaveLength(2)
  })

  it('ignores empty lines and windows line ends without issue, and reads an empty text as nothing', () => {
    const text = '\r\nArkonor\t31.026\t496.416 m3\t-\t1 km\r\n\r\nUnobtainium\t10.000\t50.000 m3\t-\t3 km\r\n'

    const result = parseSurveyPaste(text, ORE_TYPES)

    expect(result.lines).toEqual([{ typeId: ARKONOR, units: 31_026 }])
    expect(result.issues).toMatchObject([{ kind: 'unknown_ore', line: 4, name: 'Unobtainium' }])
    expect(result.issues).toHaveLength(1)
    expect(parseSurveyPaste('', ORE_TYPES)).toEqual({ lines: [], issues: [] })
  })

  it('refuses units with misplaced thousands separator or equal to 0', () => {
    const text = pasted(
      'Arkonor\t31,02\t496 m3\t-\t1 km',
      'Arkonor\t0\t0 m3\t-\t1 km',
      'Ueganite\t2.000\t10.000 m3\t-\t2 km',
    )

    const result = parseSurveyPaste(text, ORE_TYPES)

    expect(result.lines).toEqual([{ typeId: UEGANITE, units: 2_000 }])
    expect(result.issues).toMatchObject([
      { kind: 'unreadable', line: 1 },
      { kind: 'unreadable', line: 2 },
    ])
    expect(result.issues).toHaveLength(2)
  })
})
