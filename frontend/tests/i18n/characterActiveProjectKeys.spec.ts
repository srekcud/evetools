import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const REQUIRED_KEYS = ['column', 'currentlyDedicatedTo', 'error', 'hint', 'none', 'panelTitle', 'placeholder']

function activeProjectMessages(locale: unknown): Record<string, unknown> {
  const industry = (locale as { industry?: { activeProject?: Record<string, unknown> } }).industry
  return industry?.activeProject ?? {}
}

describe('industry.activeProject messages', () => {
  it('defines a text for every key of the active project setting in english and french', () => {
    for (const messages of [activeProjectMessages(en), activeProjectMessages(fr)]) {
      expect(REQUIRED_KEYS.filter(key => typeof messages[key] !== 'string' || messages[key] === '')).toEqual([])
      expect(String(messages.currentlyDedicatedTo)).toContain('{project}')
    }
  })
})
