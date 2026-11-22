import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const MESSAGES = { en, fr } as const
function jobKeys(locale: 'en' | 'fr'): Record<string, unknown> {
  return MESSAGES[locale].groupIndustry.jobs
}

describe('Shared job message keys', () => {
  it('words the runs already assigned with both placeholders and labels the runs field in English and French', () => {
    expect({
      sharedRuns: { en: jobKeys('en').sharedRuns, fr: jobKeys('fr').sharedRuns },
      runsToAssignLabelled: { en: /\S/.test(String(jobKeys('en').runsToAssign)), fr: /\S/.test(String(jobKeys('fr').runsToAssign)) },
    }).toEqual({
      sharedRuns: { en: '{linkedRuns} / {runs} runs already assigned', fr: '{linkedRuns} / {runs} runs déjà attribuées' },
      runsToAssignLabelled: { en: true, fr: true },
    })
  })
})
