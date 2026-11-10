import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

type TestLocale = 'en' | 'fr'

const MESSAGES: Record<TestLocale, unknown> = { en, fr }

const LABEL_KEYS = [
  'nav.commerce',
  'nav.appraisal',
  'appraisal.title',
  'commerce.tabs.market',
  'commerce.tabs.appraisal',
  'commerce.tabs.contracts',
]

function rawMessageOf(locale: TestLocale, key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], MESSAGES[locale])
}

function labelsOf(locale: TestLocale): Record<string, unknown> {
  return Object.fromEntries(LABEL_KEYS.map(key => [key, rawMessageOf(locale, key)]))
}

describe('Commerce module labels', () => {
  it('words the Commerce module and the appraiser in English', () => {
    expect(labelsOf('en')).toEqual({
      'nav.commerce': 'Commerce',
      'nav.appraisal': 'Appraisal',
      'appraisal.title': 'Appraisal',
      'commerce.tabs.market': 'Market',
      'commerce.tabs.appraisal': 'Appraisal',
      'commerce.tabs.contracts': 'Contracts',
    })
  })

  it('words the Commerce module and the appraiser in French', () => {
    expect(labelsOf('fr')).toEqual({
      'nav.commerce': 'Commerce',
      'nav.appraisal': 'Évaluateur',
      'appraisal.title': 'Évaluateur',
      'commerce.tabs.market': 'Marché',
      'commerce.tabs.appraisal': 'Évaluateur',
      'commerce.tabs.contracts': 'Contrats',
    })
  })
})
