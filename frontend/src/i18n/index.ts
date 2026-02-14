import { createI18n } from 'vue-i18n'
import fr from './locales/fr.json'
import en from './locales/en.json'

const savedLocale = localStorage.getItem('locale') ||
  (navigator.language.startsWith('fr') ? 'fr' : 'en')

export const i18n = createI18n({
  legacy: false,
  locale: savedLocale,
  fallbackLocale: 'fr',
  messages: { fr, en }
})
