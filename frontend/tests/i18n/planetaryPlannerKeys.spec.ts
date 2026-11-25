import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

function plannerOf(locale: { pi: object }): Record<string, unknown> | undefined {
  return (locale.pi as Record<string, Record<string, unknown> | undefined>).planner
}

describe('planetary planner labels', () => {
  it('has the same keys in English and French, including the empty catalog error', () => {
    const english = plannerOf(en)
    const french = plannerOf(fr)

    expect(english).toBeDefined()
    expect(french).toBeDefined()
    expect(leafKeys(french).sort()).toEqual(leafKeys(english).sort())
    expect(leafKeys(english)).toContain('errors.emptyCatalog')
  })

  it('words the planets table, its totals, its shortfall banner, its slots bar and the account error in both languages', () => {
    const planner = (locale: { pi: object }) => plannerOf(locale) as Record<string, Record<string, unknown> | undefined>

    for (const locale of [en, fr]) {
      const planets = planner(locale).planets
      expect(planets).toBeDefined()
      for (const section of ['total', 'shortfall', 'slots']) {
        expect(typeof (planets as Record<string, unknown>)[section]).toBe('object')
      }
      expect(typeof (planner(locale).errors as Record<string, unknown>).account).toBe('string')
    }
    expect(leafKeys(planner(fr).planets).sort()).toEqual(leafKeys(planner(en).planets).sort())
  })

  it('names the planner tab in both languages and words it differently in French', () => {
    const tabs = (locale: { pi: { tabs: object } }) => (locale.pi.tabs as Record<string, string | undefined>).planner

    expect(typeof tabs(en)).toBe('string')
    expect(typeof tabs(fr)).toBe('string')
    expect(tabs(fr)).not.toBe(tabs(en))
  })
})
