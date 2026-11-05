import { messageOf, type TestLocale } from './localeTexts'

export function expectedMessage(locale: TestLocale, key: string): string {
  try {
    return messageOf(locale, key)
  } catch {
    return `<missing ${locale} message ${key}>`
  }
}
