import { useI18n } from 'vue-i18n'

export type InterfaceLocale = 'fr' | 'en'

export const LOCALE_STORAGE_KEY = 'locale'

export function useInterfaceLocale() {
  const { locale } = useI18n()

  function setLocale(language: InterfaceLocale): void {
    locale.value = language
    localStorage.setItem(LOCALE_STORAGE_KEY, language)
  }

  return { locale, setLocale }
}
