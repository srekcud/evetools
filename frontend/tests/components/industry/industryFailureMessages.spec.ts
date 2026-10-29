import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const FAILURE_MESSAGE_KEYS = [
  'industry.costEstimation.copyCostsError',
  'industry.blacklist.loadError',
  'industry.blacklist.saveError',
  'industry.structures.loadError',
  'industry.structures.saveError',
  'industry.structures.deleteError',
]

function failureMessagesIn(locale: 'en' | 'fr'): Record<string, string> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr }, missingWarn: false, fallbackWarn: false })
  return Object.fromEntries(FAILURE_MESSAGE_KEYS.map(key => [key, i18n.global.t(key)]))
}

describe('Industry failure messages (issue 189)', () => {
  it('words every failure of the analyses and of the configuration in English', () => {
    expect(failureMessagesIn('en')).toEqual({
      'industry.costEstimation.copyCostsError': 'BPC copy costs could not be loaded.',
      'industry.blacklist.loadError': 'The blacklist could not be loaded.',
      'industry.blacklist.saveError': 'The blacklist change was not saved.',
      'industry.structures.loadError': 'Your structures could not be loaded.',
      'industry.structures.saveError': 'The structure was not saved.',
      'industry.structures.deleteError': 'The structure was not deleted.',
    })
  })

  it('words every failure of the analyses and of the configuration in French', () => {
    expect(failureMessagesIn('fr')).toEqual({
      'industry.costEstimation.copyCostsError': 'Les coûts de copie des BPC n\'ont pas pu être chargés.',
      'industry.blacklist.loadError': 'La blacklist n\'a pas pu être chargée.',
      'industry.blacklist.saveError': 'La modification de la blacklist n\'a pas été enregistrée.',
      'industry.structures.loadError': 'Vos structures n\'ont pas pu être chargées.',
      'industry.structures.saveError': 'La structure n\'a pas été enregistrée.',
      'industry.structures.deleteError': 'La structure n\'a pas été supprimée.',
    })
  })
})
