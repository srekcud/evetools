export const HELP_SECTIONS = ['faq', 'playbooks', 'calculations', 'data', 'limits'] as const
export const HELP_LANGUAGES = ['fr', 'en'] as const

export type HelpSection = (typeof HELP_SECTIONS)[number]
export type HelpLanguage = (typeof HELP_LANGUAGES)[number]

export interface HelpPage {
  section: HelpSection
  slug: string
  title: string
  source: string
}

export type HelpCatalog = Record<HelpLanguage, Record<HelpSection, HelpPage[]>>

export class HelpCatalogError extends Error {}

const HELP_FILE_PATH = /^\.\/(fr|en)\/([^/]+)\/([^/]+)\.md$/
const LEVEL_ONE_TITLE = /^# (.+)$/m

function isHelpSection(name: string): name is HelpSection {
  return (HELP_SECTIONS as readonly string[]).includes(name)
}

function emptySections(): Record<HelpSection, HelpPage[]> {
  return { faq: [], playbooks: [], calculations: [], data: [], limits: [] }
}

function parseHelpFile(path: string, source: string): { language: HelpLanguage, page: HelpPage } {
  const pathMatch = HELP_FILE_PATH.exec(path)
  if (pathMatch === null) {
    throw new HelpCatalogError(`Help file ${path} must be named ./<fr|en>/<section>/<slug>.md`)
  }
  const [, language, section, slug] = pathMatch
  if (!isHelpSection(section)) {
    throw new HelpCatalogError(`Help file ${path} is in the unknown section ${section}`)
  }
  const titleMatch = LEVEL_ONE_TITLE.exec(source)
  if (titleMatch === null) {
    throw new HelpCatalogError(`Help file ${path} has no level one title`)
  }
  return { language: language as HelpLanguage, page: { section, slug, title: titleMatch[1].trim(), source } }
}

function assertSameKeys(catalog: HelpCatalog): void {
  const keysOf = (language: HelpLanguage): Set<string> =>
    new Set(HELP_SECTIONS.flatMap(section => catalog[language][section].map(page => `${section}/${page.slug}`)))
  const frenchKeys = keysOf('fr')
  const englishKeys = keysOf('en')
  for (const key of frenchKeys) {
    if (!englishKeys.has(key)) {
      throw new HelpCatalogError(`Help page ${key} exists in fr but not in en`)
    }
  }
  for (const key of englishKeys) {
    if (!frenchKeys.has(key)) {
      throw new HelpCatalogError(`Help page ${key} exists in en but not in fr`)
    }
  }
}

export function buildHelpCatalog(files: Record<string, string>): HelpCatalog {
  const catalog: HelpCatalog = { fr: emptySections(), en: emptySections() }
  for (const [path, source] of Object.entries(files)) {
    const { language, page } = parseHelpFile(path, source)
    catalog[language][page.section].push(page)
  }
  assertSameKeys(catalog)
  return catalog
}

export const helpCatalog: HelpCatalog = buildHelpCatalog(
  import.meta.glob<string>('./{fr,en}/*/*.md', { query: '?raw', import: 'default', eager: true }),
)
