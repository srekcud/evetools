import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

export type TestLocale = 'en' | 'fr'

export const TEST_LOCALES: readonly TestLocale[] = ['fr', 'en']

const MESSAGES: Record<TestLocale, unknown> = { en, fr }

function otherLocale(locale: TestLocale): TestLocale {
  return locale === 'fr' ? 'en' : 'fr'
}

export function messageOf(locale: TestLocale, key: string): string {
  const message = key.split('.').reduce<unknown>(
    (node, part) => (node as Record<string, unknown> | undefined)?.[part],
    MESSAGES[locale],
  )
  if (typeof message !== 'string') throw new Error(`no ${locale} message for ${key}`)
  return message
}

export function expectOnlyLocaleTexts(text: string, locale: TestLocale, keys: string[]): void {
  for (const key of keys) {
    const expected = messageOf(locale, key)
    const otherLanguage = messageOf(otherLocale(locale), key)
    expect(text, key).toContain(expected)
    if (!expected.includes(otherLanguage)) expect(text, `${key} in ${otherLocale(locale)}`).not.toContain(otherLanguage)
  }
}

export function expectNoneOf(text: string, hardcodedTexts: string[]): void {
  for (const hardcoded of hardcodedTexts) expect(text).not.toContain(hardcoded)
}
