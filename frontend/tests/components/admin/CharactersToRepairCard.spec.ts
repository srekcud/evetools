import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import CharactersToRepairCard from '@/components/admin/CharactersToRepairCard.vue'
import type { AdminCharactersToRepair } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

function charactersToRepairOf(overrides: Partial<AdminCharactersToRepair> = {}): AdminCharactersToRepair {
  return {
    count: 2,
    characters: [
      {
        eveCharacterId: 1001,
        characterName: 'Mira Tolland',
        userId: '0196a1c0-0000-7000-8000-000000000001',
        userLastLoginAt: '2026-10-20T12:30:00+00:00',
        state: 'invalid',
        missingScopes: [],
        sinceAt: '2026-10-01T08:00:00+00:00',
      },
      {
        eveCharacterId: 1002,
        characterName: 'Dax Orrin',
        userId: null,
        userLastLoginAt: null,
        state: 'missing_scopes',
        missingScopes: ['esi-assets.read_assets.v1', 'esi-skills.read_skills.v1'],
        sinceAt: '2026-10-05T08:00:00+00:00',
      },
    ],
    ...overrides,
  }
}

function mountCard(charactersToRepair: AdminCharactersToRepair, locale: TestLocale = 'en') {
  return mount(CharactersToRepairCard, {
    props: { charactersToRepair },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('CharactersToRepairCard', () => {
  it('counts the characters to repair', () => {
    const wrapper = mountCard(charactersToRepairOf())

    expect(wrapper.get('[data-testid="characters-to-repair-count"]').text()).toBe('2')
  })

  it('lists each character with its name, state, missing scopes and the date since when', () => {
    const wrapper = mountCard(charactersToRepairOf())

    const rows = wrapper.findAll('[data-testid="character-to-repair"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Mira Tolland')
    expect(rows[0].text()).toContain('2026')
    expect(rows[1].text()).toContain('Dax Orrin')
    expect(rows[1].text()).toContain('esi-assets.read_assets.v1')
    expect(rows[1].text()).toContain('esi-skills.read_skills.v1')
  })

  it.each(TEST_LOCALES)('writes the %s state of each row', (locale) => {
    const wrapper = mountCard(charactersToRepairOf(), locale)

    const rows = wrapper.findAll('[data-testid="character-to-repair"]')
    expect(rows[0].text()).toContain(messageOf(locale, 'admin.charactersToRepair.state.invalid'))
    expect(rows[1].text()).toContain(messageOf(locale, 'admin.charactersToRepair.state.missing_scopes'))
  })

  it.each(TEST_LOCALES)('writes the %s "never" text when the user never logged in', (locale) => {
    const wrapper = mountCard(charactersToRepairOf(), locale)

    const rows = wrapper.findAll('[data-testid="character-to-repair"]')
    expect(rows[1].text()).toContain(messageOf(locale, 'admin.charactersToRepair.never'))
    expect(rows[0].text()).not.toContain(messageOf(locale, 'admin.charactersToRepair.never'))
  })

  it.each(TEST_LOCALES)('writes the %s empty state when nothing needs repair', (locale) => {
    const wrapper = mountCard(charactersToRepairOf({ count: 0, characters: [] }), locale)

    expect(wrapper.find('[data-testid="character-to-repair"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="characters-to-repair-empty"]').text()).toBe(messageOf(locale, 'admin.charactersToRepair.empty'))
  })

  it('names the card in both locales', () => {
    for (const locale of TEST_LOCALES) {
      expect(messageOf(locale, 'admin.charactersToRepair.title')).not.toBe('')
    }
  })
})
