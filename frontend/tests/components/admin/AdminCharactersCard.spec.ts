import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AdminCharactersCard from '@/components/admin/AdminCharactersCard.vue'
import type { AdminCharacters } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, expectOnlyLocaleTexts, type TestLocale } from '../../localeTexts'

function adminCharactersOf(overrides: Partial<AdminCharacters> = {}): AdminCharacters {
  return {
    count: 2,
    characters: [
      { eveCharacterId: 90000001, characterName: 'Mira Tolland', hasUser: true },
      { eveCharacterId: 90000002, hasUser: false },
    ],
    ...overrides,
  }
}

function mountCard(adminCharacters: AdminCharacters, locale: TestLocale = 'en') {
  return mount(AdminCharactersCard, {
    props: { adminCharacters },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('AdminCharactersCard', () => {
  it('counts the admin characters', () => {
    const wrapper = mountCard(adminCharactersOf())

    expect(wrapper.get('[data-testid="admin-characters-count"]').text()).toBe('2')
  })

  it('lists each admin character with its name and EVE character id', () => {
    const wrapper = mountCard(adminCharactersOf())

    const rows = wrapper.findAll('[data-testid="admin-character"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Mira Tolland')
    expect(rows[0].text()).toContain('90000001')
    expect(rows[1].text()).toContain('90000002')
  })

  it('shows whether a user has that character as main', () => {
    const wrapper = mountCard(adminCharactersOf())

    const rows = wrapper.findAll('[data-testid="admin-character"]')
    expect(rows[0].get('[data-testid="admin-character-user"]').text()).toBe(messageOf('en', 'admin.adminCharacters.hasUser'))
    expect(rows[1].get('[data-testid="admin-character-user"]').text()).toBe(messageOf('en', 'admin.adminCharacters.noUser'))
  })

  it('shows a placeholder instead of a name when the character has never been seen', () => {
    const wrapper = mountCard(adminCharactersOf())

    const rows = wrapper.findAll('[data-testid="admin-character"]')
    expect(rows[1].get('[data-testid="admin-character-name"]').text()).toBe(messageOf('en', 'admin.adminCharacters.unknownName'))
  })

  it('does not offer any way to change the list', () => {
    const wrapper = mountCard(adminCharactersOf())

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('input').exists()).toBe(false)
  })

  it('shows the empty state when no admin is configured', () => {
    const wrapper = mountCard(adminCharactersOf({ count: 0, characters: [] }))

    expect(wrapper.find('[data-testid="admin-character"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="admin-characters-empty"]').text()).toBe(messageOf('en', 'admin.adminCharacters.empty'))
  })

  it.each(TEST_LOCALES)('renders its texts in %s only', (locale) => {
    const wrapper = mountCard(adminCharactersOf(), locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'admin.adminCharacters.title',
      'admin.adminCharacters.character',
      'admin.adminCharacters.eveCharacterId',
      'admin.adminCharacters.user',
      'admin.adminCharacters.hasUser',
      'admin.adminCharacters.noUser',
      'admin.adminCharacters.unknownName',
      'admin.adminCharacters.readOnlyHint',
    ])
  })
})
