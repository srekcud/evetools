import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { MINING_SITE_REQUIRED_KEYS } from './miningSiteFixtures'

function flatKeysOf(node: unknown, prefix: string): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => flatKeysOf(child, prefix === '' ? key : `${prefix}.${key}`))
}

function miningSiteKeysOf(messages: unknown): string[] {
  const section = (messages as Record<string, unknown>).miningSite
  return section === undefined ? [] : flatKeysOf(section, 'miningSite').sort()
}

describe('mining site locales', () => {
  it('has the same non-empty miningSite keys in French and English, including the ones the screen reads', () => {
    const englishKeys = miningSiteKeysOf(en)
    const allKeys = [...flatKeysOf(en, '')]
    const allFrenchKeys = flatKeysOf(fr, '')

    expect({
      frenchKeys: miningSiteKeysOf(fr),
      missingInEnglish: MINING_SITE_REQUIRED_KEYS.filter(key => !allKeys.includes(key)),
      missingInFrench: MINING_SITE_REQUIRED_KEYS.filter(key => !allFrenchKeys.includes(key)),
      hasKeys: englishKeys.length > 0,
    }).toEqual({
      frenchKeys: englishKeys,
      missingInEnglish: [],
      missingInFrench: [],
      hasKeys: true,
    })
  })
})
