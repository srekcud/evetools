import { helpCatalog } from '@/help/helpCatalog'
import type { HelpLanguage } from '@/help/helpCatalog'

const PAGES_ABOUT_SOURCING = [
  { section: 'faq', slug: 'faq' },
  { section: 'faq', slug: 'industry' },
  { section: 'playbooks', slug: 't2-project' },
  { section: 'calculations', slug: 'job-cost' },
  { section: 'calculations', slug: 'job-cost-and-bonuses' },
] as const

const OLD_RULE_MARKERS: Record<HelpLanguage, string[]> = {
  en: ['150 ISK', 'jumps from Jita', 'number of jumps between Jita', 'card of the "Sourcing" tab', '"Structures" card'],
  fr: ['150 ISK', 'sauts depuis Jita', 'nombre de sauts entre Jita', 'carte « Acheter ou fabriquer » de l\'onglet', 'carte « Structures »'],
}

const COURIER_HUB_RULE: Record<HelpLanguage, { page: { section: 'faq', slug: string }, mentions: string[] }> = {
  en: { page: { section: 'faq', slug: 'industry' }, mentions: ['courier hub', 'jump bridge'] },
  fr: { page: { section: 'faq', slug: 'industry' }, mentions: ['hub de courier', 'pont'] },
}

function sourceOf(language: HelpLanguage, section: string, slug: string): string {
  const page = helpCatalog[language][section as 'faq'].find(candidate => candidate.slug === slug)
  if (page === undefined) {
    throw new Error(`Help page ${section}/${slug} is missing in ${language}`)
  }
  return page.source
}

describe('help pages on the sourcing and the stations of a project', () => {
  it.each(['en', 'fr'] as const)('keeps no trace of the old Buy or build card, the Structures card or the 150 ISK per jump rule in %s', (language) => {
    const leftovers = PAGES_ABOUT_SOURCING.flatMap(({ section, slug }) => {
      const source = sourceOf(language, section, slug)
      return OLD_RULE_MARKERS[language].filter(marker => source.includes(marker)).map(marker => `${section}/${slug}: ${marker}`)
    })

    expect(leftovers).toEqual([])
  })

  it.each(['en', 'fr'] as const)('describes the courier hub rule of the import in %s', (language) => {
    const { page, mentions } = COURIER_HUB_RULE[language]
    const source = sourceOf(language, page.section, page.slug).toLowerCase()

    expect(mentions.filter(mention => !source.includes(mention))).toEqual([])
  })
})
