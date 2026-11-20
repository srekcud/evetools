import { helpCatalog } from '@/help/helpCatalog'

const OLD_NAMES = {
  en: ['Sourcing & jobs', 'Jobs to Launch'],
  fr: ['Approvisionnement & jobs', 'Jobs a lancer', 'Jobs à lancer'],
} as const

const MATCH_JOBS_LABEL = { en: 'Match jobs', fr: 'Associer les jobs' } as const

function playbookSources(locale: 'en' | 'fr'): { slug: string; source: string }[] {
  return helpCatalog[locale].playbooks.map(page => ({ slug: page.slug, source: page.source }))
}

describe('help playbooks on the sourcing and production tabs', () => {
  it.each(['en', 'fr'] as const)('names no tab that mixes sourcing and jobs in %s', (locale) => {
    const mentions = playbookSources(locale).flatMap(({ slug, source }) => OLD_NAMES[locale]
      .filter(oldName => source.includes(oldName))
      .map(oldName => `${slug}: ${oldName}`))

    expect(mentions).toEqual([])
  })

  it.each(['en', 'fr'] as const)('places the Match jobs button of the T2 playbook under the production tab in %s', (locale) => {
    const page = playbookSources(locale).find(({ slug }) => slug === 't2-product-a-to-z')
    const matchLine = (page?.source.split('\n') ?? []).find(line => line.includes(MATCH_JOBS_LABEL[locale]))

    expect(matchLine).toContain('Production')
  })
})
