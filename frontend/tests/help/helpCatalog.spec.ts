import { buildHelpCatalog, HELP_SECTIONS, HelpCatalogError, helpCatalog } from '@/help/helpCatalog'

const MATCHING_PAGES = {
  './fr/playbooks/a.md': '# Titre A',
  './en/playbooks/a.md': '# Title A',
}

function catalogErrorOf(files: Record<string, string>): unknown {
  try {
    buildHelpCatalog(files)
  } catch (error) {
    return error
  }
  return undefined
}

function expectCatalogError(files: Record<string, string>, expectedMessagePart: string): void {
  const error = catalogErrorOf(files)

  expect(error).toBeInstanceOf(HelpCatalogError)
  expect((error as Error).message).toContain(expectedMessagePart)
}

function pageKeys(pages: { section: string, slug: string }[]): string[] {
  return pages.map(page => `${page.section}/${page.slug}`).sort()
}

describe('help catalog', () => {
  it('lists the pages of each language under their section and leaves the other sections empty', () => {
    const catalog = buildHelpCatalog(MATCHING_PAGES)

    expect(catalog.fr.playbooks).toEqual([{ section: 'playbooks', slug: 'a', title: 'Titre A', source: '# Titre A' }])
    expect(catalog.en.playbooks).toEqual([{ section: 'playbooks', slug: 'a', title: 'Title A', source: '# Title A' }])
    for (const section of HELP_SECTIONS.filter(name => name !== 'playbooks')) {
      expect(catalog.fr[section], `fr ${section}`).toEqual([])
      expect(catalog.en[section], `en ${section}`).toEqual([])
    }
  })

  it.each([
    ['French page without its English counterpart', { './fr/faq/q.md': '# Question' }, 'faq/q'],
    ['English page without its French counterpart', { './en/limits/x.md': '# Limit' }, 'limits/x'],
  ])('rejects an %s', (_case, orphanPage, expectedMessagePart) => {
    expectCatalogError({ ...MATCHING_PAGES, ...orphanPage }, expectedMessagePart)
  })

  it('rejects a section missing from the help sections, in both languages', () => {
    expectCatalogError({
      ...MATCHING_PAGES,
      './fr/unknown/a.md': '# Titre A',
      './en/unknown/a.md': '# Title A',
    }, 'unknown')
  })

  it('rejects a file without a level one title and names that file', () => {
    expectCatalogError({
      './fr/faq/q.md': 'texte sans titre',
      './en/faq/q.md': '# Question',
    }, './fr/faq/q.md')
  })

  it('rejects a file whose path is not language, section and slug, and names that file', () => {
    const malformedPaths = ['./fr/playbooks.md', './de/playbooks/a.md', 'fr/playbooks/a.md', './fr/playbooks/sub/a.md']

    for (const malformedPath of malformedPaths) {
      expectCatalogError({ ...MATCHING_PAGES, [malformedPath]: '# Titre' }, malformedPath)
    }
  })

  it('holds the same section and slug pages in French and English for the real help files', () => {
    const frenchKeys = pageKeys(HELP_SECTIONS.flatMap(section => helpCatalog.fr[section]))
    const englishKeys = pageKeys(HELP_SECTIONS.flatMap(section => helpCatalog.en[section]))

    expect(frenchKeys).toContain('playbooks/industry-structures')
    expect(frenchKeys).toEqual(englishKeys)
    expect(helpCatalog.fr.playbooks.find(page => page.slug === 'industry-structures')?.title).toBe('Configurer ses structures industrielles')
    expect(helpCatalog.en.playbooks.find(page => page.slug === 'industry-structures')?.title).toBe('Configuring your industry structures')
  })
})
