import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

function discordOf(locale: { notificationHub: object }): Record<string, unknown> | undefined {
  return (locale.notificationHub as Record<string, Record<string, unknown> | undefined>).discord
}

const REQUIRED_KEYS = ['title', 'help', 'urlLabel', 'configured', 'notConfigured', 'save', 'test', 'remove', 'testSucceeded', 'testFailed']

describe('Discord webhook labels', () => {
  it('has the same keys in English and French, with every label the settings block displays', () => {
    const english = discordOf(en)
    const french = discordOf(fr)

    expect(english).toBeDefined()
    expect(french).toBeDefined()
    expect(leafKeys(french).sort()).toEqual(leafKeys(english).sort())
    for (const key of REQUIRED_KEYS) {
      expect(typeof english?.[key]).toBe('string')
      expect(typeof french?.[key]).toBe('string')
    }
  })
})
