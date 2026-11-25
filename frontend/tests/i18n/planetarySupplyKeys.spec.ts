import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const EXISTING_SUPPLY_KEYS = ['inputs', 'output', 'supply']

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

function supplyOf(locale: { pi: object }): Record<string, unknown> {
  const supply = (locale.pi as Record<string, Record<string, unknown> | undefined>).supply
  if (!supply) throw new Error('pi.supply is missing')
  return Object.fromEntries(Object.entries(supply).filter(([key]) => !EXISTING_SUPPLY_KEYS.includes(key)))
}

describe('planetary supply labels', () => {
  it('has the same keys in English and French next to the existing production table labels', () => {
    const english = supplyOf(en)
    const french = supplyOf(fr)

    expect(leafKeys(english).length).toBeGreaterThan(0)
    expect(leafKeys(french).sort()).toEqual(leafKeys(english).sort())
  })

  it('keeps the production table labels of pi.supply in both languages', () => {
    const labels = (locale: { pi: object }) => (locale.pi as Record<string, Record<string, unknown>>).supply

    expect(EXISTING_SUPPLY_KEYS.map(key => typeof labels(en)[key])).toEqual(['string', 'string', 'string'])
    expect(EXISTING_SUPPLY_KEYS.map(key => typeof labels(fr)[key])).toEqual(['string', 'string', 'string'])
  })

  it('names the supply tab in both languages and words it differently in French', () => {
    const tab = (locale: { pi: { tabs: object } }) => (locale.pi.tabs as Record<string, string | undefined>).supply

    expect(typeof tab(en)).toBe('string')
    expect(typeof tab(fr)).toBe('string')
    expect(tab(fr)).not.toBe(tab(en))
  })
})
