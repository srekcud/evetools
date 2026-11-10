import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

type Locale = 'en' | 'fr'

const MESSAGES: Record<Locale, unknown> = { en, fr }

function rawMessageOf(locale: Locale, key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], MESSAGES[locale])
}

function bridgeKeysOf(locale: Locale): string[] {
  const bridges = rawMessageOf(locale, 'industry.bridges')
  return typeof bridges === 'object' && bridges !== null ? Object.keys(bridges).sort() : []
}

describe('Workflow bridges labels', () => {
  it('words the slot project fallback in both languages and gives the bridges the same labels in English and French', () => {
    expect({
      fallbackEn: typeof rawMessageOf('en', 'industry.slots.projectLinkFallback'),
      fallbackFr: rawMessageOf('fr', 'industry.slots.projectLinkFallback'),
      bridgeLabelsAreTheSameInBothLanguages: bridgeKeysOf('en').length > 0 && JSON.stringify(bridgeKeysOf('en')) === JSON.stringify(bridgeKeysOf('fr')),
    }).toEqual({
      fallbackEn: 'string',
      fallbackFr: 'Projet',
      bridgeLabelsAreTheSameInBothLanguages: true,
    })
  })
})
