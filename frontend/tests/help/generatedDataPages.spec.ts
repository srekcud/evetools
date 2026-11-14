import { helpCatalog, HELP_LANGUAGES } from '@/help/helpCatalog'

const EXPECTED_TITLES = {
  fr: { 'esi-scopes': 'Scopes ESI', 'sync-frequencies': 'Fréquences de synchronisation' },
  en: { 'esi-scopes': 'ESI scopes', 'sync-frequencies': 'Sync frequencies' },
} as const

describe('generated data pages', () => {
  it.each(HELP_LANGUAGES)('lists the scopes and the sync frequencies under the data section in %s', (language) => {
    const titlesBySlug = Object.fromEntries(helpCatalog[language].data.map(page => [page.slug, page.title]))

    expect(titlesBySlug).toMatchObject(EXPECTED_TITLES[language])
  })

  it.each(HELP_LANGUAGES)('renders a markdown table with one row per entry in %s', (language) => {
    expect(helpCatalog[language].data).toHaveLength(2)
    for (const page of helpCatalog[language].data) {
      const tableRows = page.source.split('\n').filter(line => line.startsWith('| '))

      expect(tableRows.length, `${language}/${page.slug}`).toBeGreaterThan(2)
    }
  })

  it('has the same number of scope rows in both languages', () => {
    const rowCount = (language: 'fr' | 'en'): number => helpCatalog[language].data
      .filter(page => page.slug === 'esi-scopes')
      .flatMap(page => page.source.split('\n'))
      .filter(line => line.startsWith('| `')).length

    expect(rowCount('fr')).toBe(rowCount('en'))
    expect(rowCount('fr')).toBeGreaterThan(0)
  })
})
