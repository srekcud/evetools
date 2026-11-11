import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MercureCookieCard from '@/components/admin/MercureCookieCard.vue'
import type { AdminMercureCookie } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

function mercureCookieOf(overrides: Partial<AdminMercureCookie> = {}): AdminMercureCookie {
  return {
    limitBytes: 4096,
    warningThresholdBytes: 3276,
    nearLimitCount: 2,
    users: [
      { userId: '0196a1c0-0000-7000-8000-000000000001', mainCharacterName: 'Mira Tolland', cookieBytes: 3900, topicCount: 36 },
      { userId: '0196a1c0-0000-7000-8000-000000000002', mainCharacterName: null, cookieBytes: 3300, topicCount: 31 },
    ],
    ...overrides,
  }
}

function mountCard(mercureCookie: AdminMercureCookie, locale: TestLocale = 'en') {
  return mount(MercureCookieCard, {
    props: { mercureCookie },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('MercureCookieCard', () => {
  it('counts the users near the browser cookie limit', () => {
    const wrapper = mountCard(mercureCookieOf())

    expect(wrapper.get('[data-testid="mercure-cookie-count"]').text()).toBe('2')
  })

  it('lists each user with the cookie size against the limit and the number of topics', () => {
    const wrapper = mountCard(mercureCookieOf())

    const rows = wrapper.findAll('[data-testid="mercure-cookie-user"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Mira Tolland')
    expect(rows[0].text()).toContain('3900')
    expect(rows[0].text()).toContain('4096')
    expect(rows[0].text()).toContain('36')
  })

  it('falls back to the user id when the user has no main character', () => {
    const wrapper = mountCard(mercureCookieOf())

    const rows = wrapper.findAll('[data-testid="mercure-cookie-user"]')
    expect(rows[1].text()).toContain('0196a1c0-0000-7000-8000-000000000002')
  })

  it('flags a user already above the browser limit', () => {
    const wrapper = mountCard(mercureCookieOf({
      nearLimitCount: 1,
      users: [{ userId: '0196a1c0-0000-7000-8000-000000000003', mainCharacterName: 'Dax Orrin', cookieBytes: 4300, topicCount: 60 }],
    }))

    expect(wrapper.find('[data-testid="mercure-cookie-over-limit"]').exists()).toBe(true)
  })

  it('does not flag a user still under the browser limit', () => {
    const wrapper = mountCard(mercureCookieOf())

    expect(wrapper.find('[data-testid="mercure-cookie-over-limit"]').exists()).toBe(false)
  })

  it.each(TEST_LOCALES)('writes the %s empty state when nobody is near the limit', (locale) => {
    const wrapper = mountCard(mercureCookieOf({ nearLimitCount: 0, users: [] }), locale)

    expect(wrapper.find('[data-testid="mercure-cookie-user"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="mercure-cookie-empty"]').text()).toBe(messageOf(locale, 'admin.mercureCookie.empty'))
  })

  it('names the card in both locales', () => {
    for (const locale of TEST_LOCALES) {
      expect(messageOf(locale, 'admin.mercureCookie.title')).not.toBe('')
    }
  })
})
