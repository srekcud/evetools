import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const MESSAGES = { en, fr } as const

function keysOf(locale: 'en' | 'fr', scope: 'tabs' | 'jobs' | 'chainFlow'): Record<string, unknown> {
  const groupIndustry = MESSAGES[locale].groupIndustry
  if (scope === 'tabs') return groupIndustry.tabs
  if (scope === 'jobs') return groupIndustry.jobs
  return groupIndustry.chain.flow
}

const KEPT_JOB_KEYS = ['matchJobs', 'matchError', 'matchFailedCharacters', 'matchFailedLines', 'matchedRuns', 'containerRemaining', 'linkJob', 'unlink', 'linkError', 'unlinkError', 'availableJobsError']

describe('Sourcing and production message keys', () => {
  it('names the first project tab Sourcing in English and Approvisionnement in French, without the old jobs key', () => {
    expect({
      en: { sourcing: keysOf('en', 'tabs').sourcing, old: 'materialsJobs' in keysOf('en', 'tabs') },
      fr: { sourcing: keysOf('fr', 'tabs').sourcing, old: 'materialsJobs' in keysOf('fr', 'tabs') },
    }).toEqual({
      en: { sourcing: 'Sourcing', old: false },
      fr: { sourcing: 'Approvisionnement', old: false },
    })
  })

  it('words the materials summary card of the production overview in English and keeps its placeholders in French', () => {
    const frenchSummary = keysOf('fr', 'chainFlow').materialsSummary
    const frenchUnknown = keysOf('fr', 'chainFlow').materialsSummaryUnknown

    expect({
      english: [keysOf('en', 'chainFlow').materialsSummary, keysOf('en', 'chainFlow').materialsSummaryUnknown],
      frenchSummaryPlaceholders: typeof frenchSummary === 'string' && frenchSummary.includes('{supplied}') && frenchSummary.includes('{total}'),
      frenchUnknownPlaceholder: typeof frenchUnknown === 'string' && frenchUnknown.includes('{count}'),
    }).toEqual({
      english: ['{supplied} / {total} material lines supplied', '{count} unknown'],
      frenchSummaryPlaceholders: true,
      frenchUnknownPlaceholder: true,
    })
  })

  it.each(['en', 'fr'] as const)('drops the jobs table title and footer keys and keeps the keys of the job actions in %s', (locale) => {
    const jobs = keysOf(locale, 'jobs')

    expect({
      removed: ['title', 'totalJobs'].filter(key => key in jobs),
      missingKept: KEPT_JOB_KEYS.filter(key => !(key in jobs)),
    }).toEqual({ removed: [], missingKept: [] })
  })
})
