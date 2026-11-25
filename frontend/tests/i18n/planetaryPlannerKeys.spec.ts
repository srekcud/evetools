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

  it('names the planner tab in both languages and words it differently in French', () => {
    const tabs = (locale: { pi: { tabs: object } }) => (locale.pi.tabs as Record<string, string | undefined>).planner

    expect(typeof tabs(en)).toBe('string')
    expect(typeof tabs(fr)).toBe('string')
    expect(tabs(fr)).not.toBe(tabs(en))
  })
})
